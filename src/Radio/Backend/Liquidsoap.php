<?php
namespace App\Radio\Backend;

use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\EventDispatcher;
use App\Radio\Adapters;
use App\Radio\AutoDJ;
use Doctrine\ORM\EntityManager;
use App\Entity;
use Monolog\Logger;
use Supervisor\Supervisor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Liquidsoap extends BackendAbstract implements EventSubscriberInterface
{
    /** @var AutoDJ */
    protected $autodj;

    /**
     * @param EntityManager $em
     * @param Supervisor $supervisor
     * @param Logger $logger
     * @param EventDispatcher $dispatcher
     * @param AutoDJ $autodj
     *
     * @see \App\Provider\RadioProvider
     */
    public function __construct(EntityManager $em, Supervisor $supervisor, Logger $logger, EventDispatcher $dispatcher, AutoDJ $autodj)
    {
        parent::__construct($em, $supervisor, $logger, $dispatcher);

        $this->autodj = $autodj;
    }

    public static function getSubscribedEvents()
    {
        return [
            AnnotateNextSong::NAME => [
                ['annotateNextSong', 0],
            ],
            WriteLiquidsoapConfiguration::NAME => [
                ['writeHeaderFunctions', 25],
                ['writePlaylistConfiguration', 20],
                ['writeHarborConfiguration', 15],
                ['writeCustomConfiguration', 10],
                ['writeLocalBroadcastConfiguration', 5],
                ['writeRemoteBroadcastConfiguration', 0],
            ],
        ];
    }

    /**
     * Write configuration from Station object to the external service.
     *
     * Special thanks to the team of PonyvilleFM for assisting with Liquidsoap configuration and debugging.
     *
     * @param Entity\Station $station
     * @return bool
     */
    public function write(Entity\Station $station): bool
    {
        $event = new WriteLiquidsoapConfiguration($station);
        $this->dispatcher->dispatch(WriteLiquidsoapConfiguration::NAME, $event);

        $ls_config_contents = $event->buildConfiguration();

        $config_path = $station->getRadioConfigDir();
        $ls_config_path = $config_path . '/liquidsoap.liq';

        file_put_contents($ls_config_path, $ls_config_contents);
        return true;
    }

    public function writeHeaderFunctions(WriteLiquidsoapConfiguration $event)
    {
        $event->prependLines([
            '# WARNING! This file is automatically generated by AzuraCast.',
            '# Do not update it directly!',
        ]);

        $station = $event->getStation();
        $config_path = $station->getRadioConfigDir();

        $event->appendLines([
            'set("init.daemon", false)',
            'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")',
            'set("log.file.path","' . $config_path . '/liquidsoap.log")',
            (APP_INSIDE_DOCKER ? 'set("log.stdout", true)' : ''),
            'set("server.telnet",true)',
            'set("server.telnet.bind_addr","'.(APP_INSIDE_DOCKER ? '0.0.0.0' : '127.0.0.1').'")',
            'set("server.telnet.port", ' . $this->_getTelnetPort($station) . ')',
            'set("harbor.bind_addrs",["0.0.0.0"])',
            '',
            'set("tag.encodings",["UTF-8","ISO-8859-1"])',
            'set("encoder.encoder.export",["artist","title","album","song"])',
            '',
            '# AutoDJ Next Song Script',
            'def azuracast_next_song() =',
            '  uri = get_process_lines("'.$this->_getApiUrlCommand($station, 'nextsong').'")',
            '  uri = list.hd(uri, default="")',
            '  log("AzuraCast Raw Response: #{uri}")',
            '  ',
            '  if uri == "" or string.match(pattern="Error", uri) then',
            '    log("AzuraCast Error: Delaying subsequent requests...")',
            '    system("sleep 2")',
            '    request.create("")',
            '  else',
            '    request.create(uri)',
            '  end',
            'end',
            '',
            '# DJ Authentication',
            'def dj_auth(user,password) =',
            '  log("Authenticating DJ: #{user}")',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'auth', ['dj_user' => '#{user}', 'dj_password' => '#{password}']).'")',
            '  ret = list.hd(ret, default="")',
            '  log("AzuraCast DJ Auth Response: #{ret}")',
            '  bool_of_string(ret)',
            'end',
            '',
            'live_enabled = ref false',
            '',
            'def live_connected(header) =',
            '  log("DJ Source connected! #{header}")',
            '  live_enabled := true',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'djon').'")',
            '  log("AzuraCast Live Connected Response: #{ret}")',
            'end',
            '',
            'def live_disconnected() =',
            '  log("DJ Source disconnected!")',
            '  live_enabled := false',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand($station, 'djoff').'")',
            '  log("AzuraCast Live Disconnected Response: #{ret}")',
            'end',
        ]);
    }

    public function writePlaylistConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $playlist_path = $station->getRadioPlaylistsDir();
        $ls_config = [];

        // Clear out existing playlists directory.
        $current_playlists = array_diff(scandir($playlist_path, SCANDIR_SORT_NONE), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Set up playlists using older format as a fallback.
        $ls_config[] = '# Fallback Playlists';

        $has_default_playlist = false;
        $playlist_objects = [];

        foreach ($station->getPlaylists() as $playlist_raw) {
            /** @var Entity\StationPlaylist $playlist_raw */
            if (!$playlist_raw->getIsEnabled()) {
                continue;
            }
            if ($playlist_raw->getType() === Entity\StationPlaylist::TYPE_DEFAULT) {
                $has_default_playlist = true;
            }

            $playlist_objects[] = $playlist_raw;
        }

        // Create a new default playlist if one doesn't exist.
        if (!$has_default_playlist) {

            $this->logger->info('No default playlist existed for this station; new one was automatically created.', ['station_id' => $station->getId(), 'station_name' => $station->getName()]);

            // Auto-create an empty default playlist.
            $default_playlist = new Entity\StationPlaylist($station);
            $default_playlist->setName('default');

            /** @var EntityManager $em */
            $this->em->persist($default_playlist);
            $this->em->flush();

            $playlist_objects[] = $default_playlist;
        }

        $playlist_weights = [];
        $playlist_vars = [];

        $special_playlists = [
            'once_per_x_songs' => [
                '# Once per x Songs Playlists',
            ],
            'once_per_x_minutes' => [
                '# Once per x Minutes Playlists',
            ],
        ];
        $schedule_switches = [];

        foreach ($playlist_objects as $playlist) {

            /** @var Entity\StationPlaylist $playlist */

            $playlist_var_name = 'playlist_' . $playlist->getShortName();

            if ($playlist->getSource() === Entity\StationPlaylist::SOURCE_SONGS) {
                $playlist_file_contents = $playlist->export('m3u', true);
                $playlist_file_path =  $playlist_path . '/' . $playlist_var_name . '.m3u';

                file_put_contents($playlist_file_path, $playlist_file_contents);

                $playlist_mode = $playlist->getOrder() === Entity\StationPlaylist::ORDER_SEQUENTIAL
                    ? 'normal'
                    : 'randomize';

                $playlist_params = [
                    'reload_mode="watch"',
                    'mode="'.$playlist_mode.'"',
                    '"'.$playlist_file_path.'"',
                ];

                $ls_config[] = $playlist_var_name . ' = audio_to_stereo(playlist('.implode(',', $playlist_params).'))';
            } else {
                $ls_config[] = $playlist_var_name . ' = audio_to_stereo(mksafe(input.http("'.$playlist->getRemoteUrl().'")))';
            }

            if ($playlist->getType() === Entity\StationPlaylist::TYPE_ADVANCED) {
                $ls_config[] = 'ignore('.$playlist_var_name.')';
            }

            switch($playlist->getType())
            {
                case Entity\StationPlaylist::TYPE_DEFAULT:
                    $playlist_weights[] = $playlist->getWeight();
                    $playlist_vars[] = $playlist_var_name;
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS:
                    $special_playlists['once_per_x_songs'][] = 'radio = rotate(weights=[1,' . $playlist->getPlayPerSongs() . '], [' . $playlist_var_name . ', radio])';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_MINUTES:
                    $delay_seconds = $playlist->getPlayPerMinutes() * 60;
                    $special_playlists['once_per_x_minutes'][] = 'delay_' . $playlist_var_name . ' = delay(' . $delay_seconds . '., ' . $playlist_var_name . ')';
                    $special_playlists['once_per_x_minutes'][] = 'radio = fallback([delay_' . $playlist_var_name . ', radio])';
                    break;

                case Entity\StationPlaylist::TYPE_SCHEDULED:
                    $play_time = $this->_getTime($playlist->getScheduleStartTime()) . '-' . $this->_getTime($playlist->getScheduleEndTime());
                    $schedule_switches[] = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_DAY:
                    $play_time = $this->_getTime($playlist->getPlayOnceTime());
                    $schedule_switches[] = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    break;
            }
        }

        $ls_config[] = '';

        // Build "default" type playlists.
        $ls_config[] = '# Standard Playlists';
        $ls_config[] = 'radio = random(weights=[' . implode(', ', $playlist_weights) . '], [' . implode(', ',
                $playlist_vars) . ']);';
        $ls_config[] = '';

        // Add in special playlists if necessary.
        foreach($special_playlists as $playlist_type => $playlist_config_lines) {
            if (count($playlist_config_lines) > 1) {
                $ls_config = array_merge($ls_config, $playlist_config_lines);
                $ls_config[] = '';
            }
        }

        $schedule_switches[] = '({ true }, radio)';
        $ls_config[] = '# Assemble final playback order';
        $fallbacks = [];

        if ($station->useManualAutoDJ()) {
            $ls_config[] = 'requests = audio_to_stereo(request.queue(id="'.$this->_getVarName('requests', $station).'"))';
            $fallbacks[] = 'requests';
        } else {
            $ls_config[] = 'dynamic = audio_to_stereo(request.dynamic(id="'.$this->_getVarName('next_song', $station).'", timeout=20., azuracast_next_song))';
            $ls_config[] = 'dynamic = cue_cut(id="'.$this->_getVarName('cue_cut', $station).'", dynamic)';
            $fallbacks[] = 'dynamic';
        }

        $fallbacks[] = 'switch([ ' . implode(', ', $schedule_switches) . ' ])';
        $fallbacks[] = 'blank(duration=2.)';

        $ls_config[] = 'radio = fallback(id="'.$this->_getVarName('playlist_fallback', $station).'", track_sensitive = '.($station->useManualAutoDJ() ? 'true' : 'false').', ['.implode(', ', $fallbacks).'])';

        $event->appendLines($ls_config);
    }

    public function writeHarborConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $harbor_params = [
            '"/"',
            'id="'.$this->_getVarName('input_streamer', $station).'"',
            'port='.$this->getStreamPort($station),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
            'max=30.',
            'buffer='.((int)($settings['dj_buffer'] ?? 5)).'.',
            'icy_metadata_charset="'.$charset.'"',
            'metadata_charset="'.$charset.'"',
            'on_connect=live_connected',
            'on_disconnect=live_disconnected',
        ];

        $event->appendLines([
            '# Live Broadcasting',
            'live = audio_to_stereo(input.harbor('.implode(', ', $harbor_params).'))',
            'ignore(output.dummy(live, fallible=true))',
            'live = fallback(id="'.$this->_getVarName('live_fallback', $station).'", track_sensitive=false, [live, blank(duration=2.)])',
            '',
            'radio = switch(id="'.$this->_getVarName('live_switch', $station).'", track_sensitive=false, [({!live_enabled}, live), ({true}, radio)])',
        ]);
    }

    public function writeCustomConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();

        // Crossfading
        $crossfade = round($settings['crossfade'] ?? 2, 1);
        if ($crossfade > 0) {
            $start_next = round($crossfade * 1.5, 2);

            $event->appendLines([
                '# Crossfading',
                'radio = crossfade(start_next=' . self::toFloat($start_next) . ',fade_out=' . self::toFloat($crossfade) . ',fade_in=' . self::toFloat($crossfade) . ',radio)',
            ]);
        }

        $event->appendLines([
            '# Apply amplification metadata (if supplied)',
            'radio = amplify(1., radio)',
        ]);

        // Custom configuration
        if (!empty($settings['custom_config'])) {
            $event->appendLines([
                '# Custom Configuration (Specified in Station Profile)',
                $settings['custom_config'],
            ]);
        }

    }

    public function writeLocalBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $ls_config = [
            '# Local Broadcasts',
        ];

        // Configure the outbound broadcast.
        $fe_settings = (array)$station->getFrontendConfig();

        // Set up broadcast to local sources.
        switch ($station->getFrontendType()) {
            case Adapters::FRONTEND_ICECAST:
                $i = 0;
                foreach ($station->getMounts() as $mount_row) {
                    $i++;

                    /** @var Entity\StationMount $mount_row */
                    if (!$mount_row->getEnableAutodj()) {
                        continue;
                    }

                    $ls_config[] = $this->_getOutputString(
                        $station,
                        $this->_getVarName('local_'.$i, $station),
                        '127.0.0.1',
                        $fe_settings['port'],
                        $mount_row->getName(),
                        '',
                        $fe_settings['source_pw'],
                        strtolower($mount_row->getAutodjFormat() ?: 'mp3'),
                        $mount_row->getAutodjBitrate() ?: 128,
                        $charset,
                        $mount_row->getIsPublic(),
                        false
                    );
                }
                break;

            case Adapters::FRONTEND_SHOUTCAST:
                $i = 0;
                foreach ($station->getMounts() as $mount_row) {
                    $i++;

                    /** @var Entity\StationMount $mount_row */
                    if (!$mount_row->getEnableAutodj()) {
                        continue;
                    }

                    $ls_config[] = $this->_getOutputString(
                        $station,
                        $this->_getVarName('local_'.$i, $station),
                        '127.0.0.1',
                        $fe_settings['port'],
                        null,
                        '',
                        $fe_settings['source_pw'].':#'.$i,
                        strtolower($mount_row->getAutodjFormat() ?: 'mp3'),
                        $mount_row->getAutodjBitrate() ?: 128,
                        $charset,
                        $mount_row->getIsPublic(),
                        true
                    );
                }
                break;

            case Adapters::FRONTEND_REMOTE:
            default:
                break;
        }

        $event->appendLines($ls_config);
    }

    public function writeRemoteBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $ls_config = [
            '# Remote Relays',
        ];

        // Set up broadcast to remote relays.
        $i = 0;
        foreach($station->getRemotes() as $remote_row) {
            $i++;

            /** @var Entity\StationRemote $remote_row */
            if (!$remote_row->getEnableAutodj()) {
                continue;
            }

            $stream_username = $remote_row->getSourceUsername();
            $stream_password = $remote_row->getSourcePassword();

            $stream_mount = $remote_row->getSourceMount();
            if (empty($stream_mount)) {
                $stream_mount = $remote_row->getMount();
            }

            switch($remote_row->getType())
            {
                case Adapters::REMOTE_SHOUTCAST1:
                    // SHOUTcast 1 doesn't have multiple streams.
                    $stream_mount = null;
                    break;

                case Adapters::REMOTE_SHOUTCAST2:
                    // Broadcasting to a separate SID is done via a password modifier in SHOUTcast 2.
                    $stream_password .= ':#'.$stream_mount;
                    $stream_mount = null;
                    break;

                case Adapters::REMOTE_ICECAST:
                    // Normal behavior.
                    break;
            }

            $remote_url_parts = parse_url($remote_row->getUrl());
            $remote_port = $remote_row->getSourcePort() ?? $remote_url_parts['port'];

            $ls_config[] = $this->_getOutputString(
                $station,
                $this->_getVarName('relay_'.$i, $station),
                $remote_url_parts['host'],
                $remote_port,
                $stream_mount,
                $stream_username,
                $stream_password,
                strtolower($remote_row->getAutodjFormat() ?: 'mp3'),
                $remote_row->getAutodjBitrate() ?: 128,
                $charset,
                false,
                $remote_row->getType() !== Adapters::REMOTE_ICECAST
            );
        }

        $event->appendLines($ls_config);
    }

    /**
     * Returns the URL that LiquidSoap should call when attempting to execute AzuraCast API commands.
     *
     * @param Entity\Station $station
     * @param $endpoint
     * @param array $params
     * @return string
     */
    protected function _getApiUrlCommand(Entity\Station $station, $endpoint, $params = [])
    {
        // Docker cURL-based API URL call with API authentication.
        if (APP_INSIDE_DOCKER) {
            $params = (array)$params;
            $params['api_auth'] = $station->getAdapterApiKey();

            $api_url = 'http://nginx/api/internal/'.$station->getId().'/'.$endpoint;
            $curl_request = 'curl -s --request POST --url '.$api_url;
            foreach($params as $param_key => $param_val) {
                $curl_request .= ' --form '.$param_key.'='.$param_val;
            }

            return $curl_request;
        }

        // Traditional shell-script call.
        $shell_path = '/usr/bin/php '.APP_INCLUDE_ROOT.'/util/cli.php';

        $shell_args = [];
        $shell_args[] = 'azuracast:internal:'.$endpoint;
        $shell_args[] = $station->getId();

        foreach((array)$params as $param_key => $param_val) {
            $shell_args [] = '--'.$param_key.'=\''.$param_val.'\'';
        }

        return $shell_path.' '.implode(' ', $shell_args);
    }

    /**
     * Configure the time offset
     *
     * @param $time_code
     * @return string
     */
    protected function _getTime($time_code)
    {
        $hours = floor($time_code / 100);
        $mins = $time_code % 100;

        $system_time_zone = \App\Utilities::get_system_time_zone();
        $app_time_zone = 'UTC';

        if ($system_time_zone !== $app_time_zone) {
            $system_tz = new \DateTimeZone($system_time_zone);
            $system_dt = new \DateTime('now', $system_tz);
            $system_offset = $system_tz->getOffset($system_dt);

            $app_tz = new \DateTimeZone($app_time_zone);
            $app_dt = new \DateTime('now', $app_tz);
            $app_offset = $app_tz->getOffset($app_dt);

            $offset = $system_offset - $app_offset;
            $offset_hours = floor($offset / 3600);

            $hours += $offset_hours;
        }

        $hours %= 24;
        if ($hours < 0) {
            $hours += 24;
        }

        return $hours . 'h' . $mins . 'm';
    }

    /**
     * Filter a user-supplied string to be a valid LiquidSoap config entry.
     *
     * @param $string
     * @return mixed
     */
    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    /**
     * Given an original name and a station, return a filtered prefixed variable identifying the station.
     *
     * @param $original_name
     * @param Entity\Station $station
     * @return string
     */
    protected function _getVarName($original_name, Entity\Station $station): string
    {
        $short_name = $this->_cleanUpString($station->getShortName());

        return (!empty($short_name))
            ? $short_name.'_'.$original_name
            : 'station_'.$station->getId().'_'.$original_name;
    }

    /**
     * Given outbound broadcast information, produce a suitable LiquidSoap configuration line for the stream.
     *
     * @param Entity\Station $station
     * @param string $stream_id
     * @param $host
     * @param $port
     * @param $mount
     * @param string $username
     * @param $password
     * @param $format
     * @param $bitrate
     * @param string $encoding "UTF-8" or "ISO-8859-1"
     * @param bool $is_public
     * @param bool $shoutcast_mode
     * @return string
     */
    protected function _getOutputString(Entity\Station $station, $stream_id, $host, $port, $mount, $username = '', $password, $format, $bitrate, $encoding = 'UTF-8', $is_public = false, $shoutcast_mode = false)
    {
        switch($format) {
            case 'aac':
                $output_format = '%fdkaac(channels=2, samplerate=44100, bitrate='.(int)$bitrate.', afterburner=true, aot="mpeg4_he_aac_v2", transmux="adts", sbr_mode=true)';
                break;

            case 'ogg':
                $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . (int)$bitrate . ')';
                break;

            case 'opus':
                $output_format = '%opus(bitrate='.(int)$bitrate.', vbr="none", application="audio", channels=2, signal="music")';
                break;

            case 'mp3':
            default:
                $output_format = '%mp3(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ', id3v2=true)';
                break;
        }

        $output_params = [];
        $output_params[] = $output_format;
        $output_params[] = 'id="'.$stream_id.'"';

        $output_params[] = 'host = "'.str_replace('"', '', $host).'"';
        $output_params[] = 'port = ' . (int)$port;
        if (!empty($username)) {
            $output_params[] = 'user = "'.str_replace('"', '', $username).'"';
        }
        $output_params[] = 'password = "'.str_replace('"', '', $password).'"';
        if (!empty($mount)) {
            $output_params[] = 'mount = "'.$mount.'"';
        }

        $output_params[] = 'name = "' . $this->_cleanUpString($station->getName()) . '"';
        $output_params[] = 'description = "' . $this->_cleanUpString($station->getDescription()) . '"';

        if (!empty($station->getUrl())) {
            $output_params[] = 'url = "' . $this->_cleanUpString($station->getUrl()) . '"';
        }

        $output_params[] = 'public = '.($is_public ? 'true' : 'false');
        $output_params[] = 'encoding = "'.$encoding.'"';

        if ($shoutcast_mode) {
            $output_params[] = 'protocol="icy"';
        }

        $output_params[] = 'radio';

        return 'output.icecast(' . implode(', ', $output_params) . ')';
    }

    /**
     * @inheritdoc
     */
    public function getCommand(Entity\Station $station): ?string
    {
        if ($binary = self::getBinary()) {
            $config_path = $station->getRadioConfigDir() . '/liquidsoap.liq';
            return $binary . ' ' . $config_path;
        }

        return '/bin/false';
    }

    /**
     * If a station uses Manual AutoDJ mode, enqueue a request directly with Liquidsoap.
     *
     * @param Entity\Station $station
     * @param $music_file
     * @return array
     * @throws \App\Exception
     */
    public function request(Entity\Station $station, $music_file)
    {
        $requests_var = $this->_getVarName('requests', $station);

        $queue = $this->command($station, $requests_var.'.queue');

        if (!empty($queue[0])) {
            throw new \Exception('Song(s) still pending in request queue.');
        }

        return $this->command($station, $requests_var.'.push ' . $music_file);
    }

    /**
     * Tell LiquidSoap to skip the currently playing song.
     *
     * @param Entity\Station $station
     * @return array
     * @throws \App\Exception
     */
    public function skip(Entity\Station $station)
    {
        return $this->command(
            $station,
            $this->_getVarName('local_1', $station).'.skip'
        );
    }

    /**
     * Tell LiquidSoap to disconnect the current live streamer.
     *
     * @param Entity\Station $station
     * @return array
     * @throws \App\Exception
     */
    public function disconnectStreamer(Entity\Station $station)
    {
        $current_streamer = $station->getCurrentStreamer();
        $disconnect_timeout = (int)$station->getDisconnectDeactivateStreamer();

        if ($current_streamer instanceof Entity\StationStreamer && $disconnect_timeout > 0) {
            $current_streamer->deactivateFor($disconnect_timeout);

            $this->em->persist($current_streamer);
            $this->em->flush();
        }

        return $this->command(
            $station,
            $this->_getVarName('input_streamer', $station).'.stop'
        );
    }

    /**
     * Execute the specified remote command on LiquidSoap via the telnet API.
     *
     * @param Entity\Station $station
     * @param $command_str
     * @return array
     * @throws \App\Exception
     */
    public function command(Entity\Station $station, $command_str)
    {
        $fp = stream_socket_client('tcp://'.(APP_INSIDE_DOCKER ? 'stations' : 'localhost').':' . $this->_getTelnetPort($station), $errno, $errstr, 20);

        if (!$fp) {
            throw new \App\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    /**
     * Returns the port used for DJs/Streamers to connect to LiquidSoap for broadcasting.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    public function getStreamPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();

        if (!empty($settings['dj_port'])) {
            return (int)$settings['dj_port'];
        }

        // Default to frontend port + 5
        $frontend_config = (array)$station->getFrontendConfig();
        $frontend_port = $frontend_config['port'] ?? (8000 + (($station->getId() - 1) * 10));

        return $frontend_port + 5;
    }

    /**
     * Returns the internal port used to relay requests and other changes from AzuraCast to LiquidSoap.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    protected function _getTelnetPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();
        return (int)($settings['telnet_port'] ?? ($this->getStreamPort($station) - 1));
    }

    /*
     * INTERNAL LIQUIDSOAP COMMANDS
     */

    public function authenticateStreamer(Entity\Station $station, $user, $pass): string
    {
        // Allow connections using the exact broadcast source password.
        $fe_config = (array)$station->getFrontendConfig();
        if (!empty($fe_config['source_pw']) && strcmp($fe_config['source_pw'], $pass) === 0) {
            return 'true';
        }

        // Handle login conditions where the username and password are joined in the password field.
        if (strpos($pass, ',') !== false) {
            [$user, $pass] = explode(',', $pass);
        }
        if (strpos($pass, ':') !== false) {
            [$user, $pass] = explode(':', $pass);
        }

        /** @var Entity\Repository\StationStreamerRepository $streamer_repo */
        $streamer_repo = $this->em->getRepository(Entity\StationStreamer::class);

        $streamer = $streamer_repo->authenticate($station, $user, $pass);

        if ($streamer instanceof Entity\StationStreamer) {
            // Successful authentication: update current streamer on station.
            $station->setCurrentStreamer($streamer);
            $this->em->persist($station);
            $this->em->flush();

            return 'true';
        }

        return 'false';
    }

    /**
     * Pulls the next song from the AutoDJ, dispatches the AnnotateNextSong event and returns the built result.
     *
     * @param Entity\Station $station
     * @param bool $as_autodj
     * @return string
     */
    public function getNextSong(Entity\Station $station, $as_autodj = false): string
    {
        /** @var Entity\SongHistory|null $sh */
        $sh = $this->autodj->getNextSong($station, $as_autodj);

        $event = new AnnotateNextSong($station, $sh);
        $this->dispatcher->dispatch(AnnotateNextSong::NAME, $event);

        return $event->buildAnnotations();
    }

    /**
     * Event Handler function for the AnnotateNextSong event.
     *
     * @param AnnotateNextSong $event
     */
    public function annotateNextSong(AnnotateNextSong $event)
    {
        $sh = $event->getNextSong();

        if ($sh instanceof Entity\SongHistory) {
            $media = $sh->getMedia();
            if ($media instanceof Entity\StationMedia) {
                $event->setSongPath($media->getFullPath());
                $event->addAnnotations($media->getAnnotations());
            }
        } else {
            $error_file = APP_INSIDE_DOCKER
                ? '/usr/local/share/icecast/web/error.mp3'
                : APP_INCLUDE_ROOT . '/resources/error.mp3';

            $event->setSongPath($error_file);
        }
    }

    public function toggleLiveStatus(Entity\Station $station, $is_streamer_live = true): void
    {
        $station->setIsStreamerLive($is_streamer_live);

        $this->em->persist($station);
        $this->em->flush();
    }

    /**
     * Convert an integer or float into a Liquidsoap configuration compatible float.
     *
     * @param float $number
     * @param int $decimals
     * @return string
     */
    public static function toFloat($number, $decimals = 2): string
    {
        if ((int)$number == $number) {
            return (int)$number.'.';
        }

        return number_format($number, $decimals, '.', '');
    }

    /**
     * @inheritdoc
     */
    public static function getBinary()
    {
        $user_base = \dirname(APP_INCLUDE_ROOT);
        $new_path = $user_base . '/.opam/system/bin/liquidsoap';

        $legacy_path = '/usr/bin/liquidsoap';

        if (APP_INSIDE_DOCKER || file_exists($new_path)) {
            return $new_path;
        }
        if (file_exists($legacy_path)) {
            return $legacy_path;
        }
        return false;
    }
}

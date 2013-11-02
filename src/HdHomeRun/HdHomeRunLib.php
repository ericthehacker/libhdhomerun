<?php

namespace HdHomeRun;

/**
 * HDHomeRun library
 *
 * @author Eric Wiese
 */
class HdHomeRunLib
{
    const VERSION = '0.0.1';

    const LOG_LEVEL_DEBUG = 0;
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_ERROR = 2;

    const DISCOVER_COMMAND = 'discover';
    const SYS_FEATURES_COMMAND = 'get /sys/features';
    const SET_CHANNELMAP_COMMAND = 'set /tuner%s/channelmap';
    const SCAN_COMMAND = 'scan /tuner%s';
    const SET_CHANNEL_COMMAND = 'set /tuner%s/channel';
    const TUNER_STATUS_COMMAND = 'get /tuner%s/status';
    const SET_PROGRAM_COMMAND = 'set /tuner%s/program';
    const SET_TARGET_COMMAND = 'set /tuner%s/target';
    const GET_TARGET_COMMAND = 'get /tuner%s/target';

    const NO_DEVICES_RESPONSE = 'no devices found';
    const FEATURES_CHANNELMAP_KEY = 'channelmap';
    const LOCK_PREFIX = 'LOCK:';
    const LOCK_NONE = 'none';
    const PROGRAM_PREFIX = 'PROGRAM';
    const PROTOCOL_RTP = 'rtp';
    const PROTOCOL_UDP = 'udp';

    /** @var string */
    private $_cmdPath = null;
    /** @var Closure */
    private $_logCallback = null;
    /** @var string */
    private $_currentDeviceId = null;
    /** @var string */
    private $_currentChannelMap = null;
    /** @var int */
    private $_currentTunerId = null;
    /** @var string */
    private $_currentChannel = null;
    /** @var int */
    private $_currentProgram = null;

    //accessors
    /**
     * Get current tuner ID
     *
     * @return int
     */
    public function getCurrentTunerId() {
        if(is_null($this->_currentTunerId)) {
            $this->_throwException('No tuner ID currently set.');
        }
        return $this->_currentTunerId;
    }

    /**
     * Set current tuner ID
     *
     * @param int $currentTunerId
     */
    public function setCurrentTunerId($currentTunerId) {
        if(!in_array($currentTunerId, $this->getTuners($this->getCurrentDeviceId()))) {
            $this->_throwException(sprintf('Invalid tuner ID %s. Device %s supports these tuner IDs: %s.',
                                   $currentTunerId,
                                   $this->getCurrentDeviceId(),
                                   implode(', ', $this->getTuners($this->getCurrentDeviceId()))));
        }

        $this->_currentTunerId = $currentTunerId;
    }

    /**
     * get current device ID
     *
     * @throws HdHomeRunConfigException
     * @return string
     */
    public function getCurrentDeviceId() {
        if(is_null($this->_currentDeviceId)) {
            $this->_throwException('No current device ID set.');
        }
        return $this->_currentDeviceId;
    }

    /**
     * set current device ID
     *
     * @param string $currentDeviceId
     */
    public function setCurrentDeviceId($currentDeviceId) {
        $this->_currentDeviceId = $currentDeviceId;
    }

    /**
     * get current channel map
     *
     * @throws HdHomeRunConfigException
     * @return string
     */
    public function getCurrentChannelMap() {
        if(is_null($this->_currentChannelMap)) {
            $this->_throwException('No current channel map set.');
        }
        return $this->_currentChannelMap;
    }

    /**
     * Set current channel map
     *
     * @param string $currentChannelMap
     * @throws HdHomeRunConfigException
     */
    public function setCurrentChannelMap($currentChannelMap) {
        //sanity checking, if possible
        $features = $this->getFeatures($this->getCurrentDeviceId());
        if(isset($features[self::FEATURES_CHANNELMAP_KEY])) {
            if(!in_array($currentChannelMap, $features[self::FEATURES_CHANNELMAP_KEY])) {
                $this->_throwException(
                    sprintf('%s is not an available channel map. Device %s supports these channel maps: %s',
                        $currentChannelMap, $this->getCurrentDeviceId(), implode(', ', $features[self::FEATURES_CHANNELMAP_KEY]))
                );
            }
        }

        //inform tuner
        $this->_execTuner(self::SET_CHANNELMAP_COMMAND,
                          $this->getCurrentTunerId(),
                          $this->getCurrentDeviceId(),
                          array($currentChannelMap));

        $this->_currentChannelMap = $currentChannelMap;
    }

    /**
     * get current channel
     *
     * @return string
     */
    public function getCurrentChannel() {
        if(is_null($this->_currentChannel)) $this->_throwException('No channel currently set.');
        return $this->_currentChannel;
    }

    /**
     * set current channel
     *
     * @param string $currentChannel
     */
    public function setCurrentChannel($currentChannel) {
        $this->_execTuner(self::SET_CHANNEL_COMMAND, null, null, array($currentChannel));

        $this->_currentChannel = $currentChannel;
    }

    /**
     * Get current program index
     *
     * @return int
     */
    public function getCurrentProgram() {
        if(is_null($this->_currentProgram)) $this->_throwException('No program currently set.');
        return $this->_currentProgram;
    }

    /**
     * Set current program
     *
     * @param int $currentProgram
     */
    public function setCurrentProgram($currentProgram) {
        $this->_execTuner(self::SET_PROGRAM_COMMAND, null, null, array($currentProgram));

        $this->_currentProgram = $currentProgram;
    }

    /**
     * Set streaming target
     *
     * @param $targetIp
     * @param $targetPort
     * @param string $targetProtocol
     */
    public function setTarget($targetIp, $targetPort, $targetProtocol = self::PROTOCOL_RTP) {
        $param = sprintf('%s://%s:%s', $targetProtocol, $targetIp, $targetPort);

        $this->log(
          sprintf(
                    'Setting tuner %s, device %s to target %s.',
                    $this->getCurrentTunerId(),
                    $this->getCurrentDeviceId(),
                    $param
                 ),
          self::LOG_LEVEL_INFO
        );

        $this->_execTuner(self::SET_TARGET_COMMAND, null, null, array($param));
    }

    /**
     * Get current target
     *
     * @return string
     */
    public function getTarget() {
        $response = $this->_execTuner(self::GET_TARGET_COMMAND, null, null);

        $this->log(
            sprintf(
                'Found target for tuner %s, device %s: %s',
                $this->getCurrentTunerId(),
                $this->getCurrentDeviceId(),
                $response
            ),
            self::LOG_LEVEL_DEBUG
        );

        return $response;
    }
    //end accessors

    //util methods
    /**
     * Explode string, but omit empty values
     *
     * @param $separator
     * @param $string
     * @return array
     */
    protected function _explode($separator, $string) {
        $values = explode($separator, $string);

        $return = array();
        foreach($values as $value) {
            $value = trim($value);
            if(strlen($value) == 0) continue;

            $return[] = $value;
        }

        return $return;
    }
    //end util methods

    /**
     * Instantiate library
     *
     * @param $hdHomeRuneCmdPath - path to hdhomerun_config shell command
     * @param Closure $logCallback - callback method -- accepts string
     */
    function __construct($hdHomeRuneCmdPath, Closure $logCallback = null) {
        $this->_setCmd($hdHomeRuneCmdPath);
        $this->_logCallback = $logCallback;
    }

    /**
     * Set hdhomerun_config cmd path from input in constructor
     *
     * @param $cmdInput
     */
    protected function _setCmd($cmdInput) {
        $this->_cmdPath = $cmdInput; //@todo: resolve cmd path fully
    }

    /**
     * If log callback provided, log to it
     *
     * @param $message
     * @param int $level
     */
    public function log($message, $level) {
        if(is_null($this->_logCallback)) {
            return;
        }

        $this->_logCallback->__invoke($message, $level);
    }

    /**
     * Throw exception of proper type
     *
     * @param $message
     * @throws HdHomeRunException
     */
    protected function _throwException($message) {
        throw new HdHomeRunException($this, $message);
    }

    /**
     * Get fully resolved hdhomerun_config command path
     *
     * @return null
     */
    protected function _getFullCmdPath() {
        return $this->_cmdPath;
    }

    /**
     * Actually executes command using shell_exec()
     *
     * @param $hdHomeRunCommand - command to run
     * @param null $homerunId - hdhomerun ID
     * @param array $params - params to space separate after command
     * @return string
     */
    protected function _exec($hdHomeRunCommand, $homerunId = null, array $params = array()) {
        $cmd = $this->_getFullCmdPath() . ' ';

        if(!empty($homerunId)) {
            $cmd .= $homerunId . ' ';
        }

        $cmd .= $hdHomeRunCommand;

        $cmd .= ' ' . implode(' ', $params);


        $return = shell_exec($cmd);
        $logMessage = sprintf("Executing CMD: '%s' ...\n... Response: '%s'.", $cmd, $return);
        $this->log($logMessage, self::LOG_LEVEL_DEBUG);

        return $return;
    }

    /**
     * Execute a command with a given hdhomerun and tuner context.
     *
     * @param $hdHomeRunCommand
     * @param $tunerId
     * @param $homerunId
     * @param array $params
     * @return string
     */
    protected function _execTuner($hdHomeRunCommand, $tunerId = null, $homerunId = null, array $params = array()) {
        if(is_null($tunerId)) $tunerId = $this->getCurrentTunerId();
        if(is_null($homerunId)) $homerunId = $this->getCurrentDeviceId();

        $command = sprintf($hdHomeRunCommand, $tunerId);
        return $this->_exec($command, $homerunId, $params);
    }

    /**
     * Get list of tuners on device
     *
     * @param $id
     * @return array
     */
    public function getTuners($id) {
        return array(0, 1); //@todo: pull this from device
    }

    public function getFeatures($id) {
        $result = $this->_exec(self::SYS_FEATURES_COMMAND, $id);

        $lines = $this->_explode("\n", $result);

        $features = array();

        foreach($lines as $line) {
            $keyValuePair = explode(':', $line);
            $key = $keyValuePair[0];
            $values = $this->_explode(' ', $keyValuePair[1]);

            $features[$key] = $values;
        }
        
        $this->log('features for ' . $id . ':', self::LOG_LEVEL_DEBUG);
        $this->log($features, self::LOG_LEVEL_DEBUG);

        return $features;
    }

    /**
     * Discover devices
     *
     * @return array
     */
    public function discover() {
        $response = $this->_exec(self::DISCOVER_COMMAND);

        if($response == self::NO_DEVICES_RESPONSE) {
            $this->_throwException('No devices found');
        }

        //example response: hdhomerun device 103440A8 found at 192.168.1.217

        $lines = $this->_explode("\n", $response);

        $devices = array();

        foreach($lines as $line) {
            if(empty($line)) {
                continue;
            }

            $words = $this->_explode(' ', $line);
            $id = $words[2];
            $ip = $words[5];
            $devices[$id] = array('ip' => $ip, 'tuners' => $this->getTuners($id));
        }

        $this->log('discovered devices: ', self::LOG_LEVEL_DEBUG);
        $this->log($devices, self::LOG_LEVEL_DEBUG);

        return $devices;
    }

    /**
     * Performs a channel scan on the current device
     * and tuner and returns results as array
     *
     * @return array
     */
    public function scan() {
        $response = $this->_execTuner(self::SCAN_COMMAND);

        $lines = $this->_explode("\n", $response);

        $channels = array();

        $i=0;
        while($i < count($lines)) {
            $scanLine = $lines[$i++];
            $resultLine = $lines[$i++];

            $resultWords = $this->_explode(' ', $resultLine);

            if($resultWords[0] != self::LOCK_PREFIX) {
                $this->_throwException(
                    sprintf('SCAN: unexpected result line "%s". Expected prefix of "%s"', $resultLine, self::LOCK_PREFIX)
                );
            }

            if($resultWords[1] == self::LOCK_NONE) continue; //no lock, don't bother with the rest

            $scanWords = $this->_explode(' ', $scanLine);
            $channelMapWords = $this->_explode(':', $scanWords[2]);
            $internalChannel = $scanWords[1];
            $friendlyChannel = intval($channelMapWords[1]);

            //@todo: TSID line?

            $programs = array();
            $j = $i + 1;
            while($j < count($lines)) {
                $lineWords = $this->_explode(' ', $lines[$j]);
                if($lineWords[0] != self::PROGRAM_PREFIX) break;

                $programNumber = intval($lineWords[1]);

                $programs[$programNumber] = array(
                    'friendly_number'   => $lineWords[2],
                    'friendly_name'     => $lineWords[3]
                );
                $j++;
            }
            $i = $j; //keep outer loop working

            if(empty($programs)) {
                $this->log(sprintf('Odd ... found no programs for channel %s (%s):', $friendlyChannel, $internalChannel), self::LOG_LEVEL_INFO);
            }

            $channels[$friendlyChannel] = array(
                'internal_channel' => $internalChannel,
                //@todo: other LOCK words are probably interesting

                'programs' => $programs
            );
        }

        $this->log('Found these channels during scan:', self::LOG_LEVEL_DEBUG);
        $this->log($channels, self::LOG_LEVEL_DEBUG);

        return $channels;
    }

    /**
     * Get status of tuner (signal strength, etc)
     *
     * @param null $tunerId
     * @param null $homerunId
     * @return array
     */
    public function getTunerStatus($tunerId = null, $homerunId = null) {
        $tunerId = is_null($tunerId) ? $this->getCurrentTunerId() : $tunerId;
        $homerunId = is_null($homerunId) ? $this->getCurrentDeviceId() : $homerunId;

        $result = $this->_execTuner(self::TUNER_STATUS_COMMAND, $tunerId, $homerunId);

        $words = $this->_explode(' ', $result);

        $status = array();
        foreach($words as $word) {
            $keyValuePair = $this->_explode('=', $word);
            $status[$keyValuePair[0]] = $keyValuePair[1];
        }

        $this->log(
            sprintf('Checking status of tuner %s on device %s:', $tunerId, $homerunId),
            self::LOG_LEVEL_DEBUG
        );
        $this->log($status, self::LOG_LEVEL_DEBUG);

        return $status;
    }
}

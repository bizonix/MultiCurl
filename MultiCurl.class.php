<?php
/**
 * MultiCurl class provides a convenient way to execute parallel HTTP(S)
 * requests via PHP MULTI CURL extension with additional restrictions.
 * For example: start 100 downloads with 2 parallel sessions, and get only
 * first 100 Kb per session.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Lesser General Public License for more details.
 *
 * @author    Vadym Timofeyev <tvad@mail333.com> http://weblancer.net/users/tvv/
 * @copyright 2007-2010 Vadym Timofeyev
 * @license   http://www.gnu.org/licenses/lgpl-3.0.txt
 * @version   1.07
 * @since     PHP 5.0
 * @example   examples/example.php How to use MultiCurl class library.
 */
abstract class MultiCurl {
    /**
     * Maximal number of CURL multi sessions. Default: 10 sessions.
     *
     * @var integer
     */
    private $maxSessions = 10;

    /**
     * Maximal size of downloaded content. Default: 10 Mb (10 * 1024 * 1024).
     *
     * @var integer
     */
    private $maxSize = 10485760;

    /**
     * Common CURL options (used for all requests).
     *
     * @var array
     */
    private $curlOptions;

    /**
     * Current CURL multi sessions.
     *
     * @var array
     */
    private $sessions = array();

    /**
     * Class constructor. Setup primary parameters.
     *
     * @param array $curlOptions Common CURL options.
     */
    public function __construct($curlOptions = array()) {
		if(empty($curlOptions))
		{
			$header[] = "Accept: */*";
			$header[] = "Cache-Control: max-age=0";
			$header[] = "Accept-Charset: utf-8;q=0.7,*;q=0.7";
			$header[] = "Accept-Language: en-us,en;q=0.5";
			$header[] = "Pragma: ";
			
			$curlOptions=array(
				CURLOPT_HEADER     		=> true,
				CURLOPT_HTTPHEADER 		=> $header,
				CURLOPT_USERAGENT  		=> 'Googlebot/2.1 (+http://www.google.com/bot.html)',
			    CURLOPT_CONNECTTIMEOUT 	=> 20,
			    CURLOPT_TIMEOUT 		=> 10
			);
		}
        $this->setCurlOptions($curlOptions);
    }

    /**
     * Class destructor. Close opened sessions.
     */
    public function __destruct() {
        foreach ($this->sessions as $i => $sess) {
            $this->destroySession($i);
        }
    }

    /**
     * Adds new URL to query.
     *
     * @param mixed $url URL for downloading.
     * @param array $curlOptions CURL options for current request.
     */
    public function addUrl($url, $curlOptions = array()) {
        // Check URL
        if (!$url) {
            throw new Exception('URL is empty!');
        }

        // Check array of URLs
        if (is_array($url)) {
            foreach ($url as $s) {
                $this->addUrl($s, $curlOptions);
            }
            return;
        }

        // Check query
        while (count($this->sessions) == $this->maxSessions) {
            $this->checkSessions();
        }

        // Init new CURL session
        $ch = curl_init($url);
        foreach ($this->curlOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }
        foreach ($curlOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        // Init new CURL multi session
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $this->sessions[] = array($mh, $ch, $url);
        $sessions_key = array_keys($this->sessions);
        $this->execSession(array_pop($sessions_key));
    }

    /**
     * Waits CURL milti sessions.
     */
    public function wait() {
        while (count($this->sessions)) {
            $this->checkSessions();
        }
    }

    /**
     * Executes all active CURL multi sessions.
     */
    protected function checkSessions() {
        foreach ($this->sessions as $i => $sess) {
            if ($this->multiSelect($sess[0]) != -1) {
                $this->execSession($i);
            }
            else {
                throw new Exception('Multicurl loop detected!');
            }
        }
    }

    /**
     * Executes CURL multi session, check session status and downloaded size.
     *
     * @param integer $i A session id.
     */
    protected function execSession($i) {
    	list($mh, $ch) = $this->sessions[$i];
    	if ($mh) {
            do {
                $mrc = curl_multi_exec($mh, $act);
            } while ($act > 0);
            if (!$act || $mrc !== CURLM_OK || curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD) >= $this->maxSize) {
                $this->closeSession($i);
            }
    	}
    }

    /**
     * Replace curl_multi_select.
     *
     * @see http://php.net/manual/en/function.curl-multi-select.php#110869
     * @param resource $mh A cURL multi handle returned by curl_multi_init().
     * @param float $timeout Time, in seconds, to wait for a response.
     */
    protected function multiSelect($mh, $timeout = 1.0) {
        $ts = microtime(true);

        do {
            $mrc = curl_multi_exec($mh, $act);
            $ct = microtime(true);
            $t = $ct - $ts;
            if ($t >= $timeout) {
                return CURLM_CALL_MULTI_PERFORM;
            }
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }

    /**
     * Closes session.
     *
     * @param integer $i A session id.
     */
    protected function closeSession($i) {
        list(, $ch, $url) = $this->sessions[$i];

        $content = !curl_error($ch) ? curl_multi_getcontent($ch) : null;
        $info = curl_getinfo($ch);    
        $this->destroySession($i);
        $this->onLoad($url, $content, $info);
    }

    /**
     * Destroys session.
     *
     * @param integer $i A session id.
     */
    protected function destroySession($i) {
        list($mh, $ch,) = $this->sessions[$i];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        unset($this->sessions[$i]);
    }

    /**
     * Gets maximal number of CURL multi sessions.
     *
     * @return integer Maximal number of CURL multi sessions.
     */
    public function getMaxSessions() {
        return $this->maxSessions;
    }

    /**
     * Sets maximal number of CURL multi sessions.
     *
     * @param integer $maxSessions Maximal number of CURL multi sessions.
     */
    public function setMaxSessions($maxSessions) {
        if ((int)$maxSessions <= 0) {
            throw new Exception('Max sessions number must be bigger then zero!');
        }

        $this->maxSessions = (int)$maxSessions;
    }

    /**
     * Gets maximal size limit for downloaded content.
     * 
     * @return integer Maximal size limit for downloaded content.
     */
    public function getMaxSize() {
        return $this->maxSize;
    }

    /**
     * Sets maximal size limit for downloaded content.
     *
     * @param integer $maxSize Maximal size limit for downloaded content.
     */
    public function setMaxSize($maxSize) {
        if ((int)$maxSize <= 0) {
            throw new Exception('Max size limit must be bigger then zero!');
        }

        $this->maxSize = (int)$maxSize;
    }

    /**
     * Gets CURL options for all requests.
     *
     * @return array CURL options.
     */
    public function getCurlOptions() {
        return $this->curlOptions;
    }

    /**
     * Sets CURL options for all requests.
     *
     * @param array $curlOptions CURL options.
     */
    public function setCurlOptions($curlOptions) {
        if (!array_key_exists(CURLOPT_FOLLOWLOCATION, $curlOptions)) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = 1;
        }
        $curlOptions[CURLOPT_RETURNTRANSFER] = 1;
        $this->curlOptions = $curlOptions;
    }

    /**
     * OnLoad callback event.
     *
     * @param string $url URL for downloading.
     * @param string $content Downloaded content.
     * @param array $info CURL session information.
     */
    protected abstract function onLoad($url, $content, $info);

    /**
     * Checks CURL extension, etc.
     */
    public static function checkEnvironment() {
        if (!extension_loaded('curl')) {
            throw new Exception('CURL extension not loaded');
        }
    }
}

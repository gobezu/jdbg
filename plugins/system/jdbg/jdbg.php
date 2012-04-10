<?php
// $Copyright$

// no direct access
defined('_JEXEC') or die('Restricted access');

class plgSystemJdbg extends JPlugin {
        function plgSystemJdbg(& $subject, $config) {
                $document = JFactory::getDocument();

                if ($document->getType() != 'html') return;

                parent::__construct($subject, $config);
                
                $params = $this->params;

                jdbg::$MODE = $params->get('mode', 'krumo');

                if (jdbg::$MODE == 'kint') {
                        jdbg::$MODE = 'krumo';
                        jdbg::$LIB = 'kint';
                        require_once dirname(__FILE__) . '/kint/kint.class.php';
                } else if (jdbg::$MODE == 'krumo') {
                        require_once dirname(__FILE__) . '/krumo/class.krumo.php';
                        define('KRUMO_INIFILE', dirname(__FILE__) . '/krumo/krumo.ini');
                } else if (jdbg::$MODE == 'firephp') {
                        jdbg::$LIB = 'firephp';
                        require_once dirname(__FILE__) . '/FirePHPCore/fb.php';
                        FB::setEnabled();
                }
                
                jdbg::$JDEBUG_OVERRIDE = $params->get('override', true);

                if (!JDEBUG && !jdbg::$JDEBUG_OVERRIDE) return;

                $app = JFactory::getApplication();

                if ($params->get('mode') != 'both' && $app->getName() != $params->get('where')) return;

                if (jdbg::$MODE == 'file') {
                        jimport('joomla.filesystem.file');
                        
                        jdbg::$LOG_FILE = $params->get('log_file', '/tmp/jdbg.log');
                        jdbg::$LOG_FILE = JPath::clean(JPATH_SITE . '/' . jdbg::$LOG_FILE);

                        if ($params->get('reset_on_page_load') && JFile::exists(jdbg::$LOG_FILE)) JFile::delete(jdbg::$LOG_FILE);
                }

                jdbg::$INTS = $params->get('ints');

                if (jdbg::$MODE == 'krumo' && jdbg::$LIB == 'krumo') plgSystemJdbg::generate_krumo_ini_file($params);
        }

        private static function generate_krumo_ini_file($params) {
                $url = JUri::base() . 'plugins/system/jdbg/krumo/';
                $skin = $params->get('skin');
                $p = array('skin' => array('selected' => $skin), 'css' => array('url' => $url));
                plgSystemJdbg::write_ini_file($p, KRUMO_INIFILE, true);
        }

        // Taken from somewhere on php.net (don't remember where)
        private static function write_ini_file($assoc_arr, $path, $has_sections=FALSE) {
                $content = "";

                if ($has_sections) {
                        foreach ($assoc_arr as $key => $elem) {
                                $content .= "[" . $key . "]\n";
                                foreach ($elem as $key2 => $elem2) {
                                        if (is_array($elem2)) {
                                                for ($i = 0; $i < count($elem2); $i++) {
                                                        $content .= $key2 . "[] = \"" . $elem2[$i] . "\"\n";
                                                }
                                        } else if ($elem2 == "")
                                                $content .= $key2 . " = \n";
                                        else
                                                $content .= $key2 . " = \"" . $elem2 . "\"\n";
                                }
                        }
                } else {
                        foreach ($assoc_arr as $key => $elem) {
                                if (is_array($elem)) {
                                        for ($i = 0; $i < count($elem); $i++) {
                                                $content .= $key2 . "[] = \"" . $elem[$i] . "\"\n";
                                        }
                                } else if ($elem == "")
                                        $content .= $key2 . " = \n";
                                else
                                        $content .= $key2 . " = \"" . $elem . "\"\n";
                        }
                }

                if (!$handle = fopen($path, 'w+')) {
                        return false;
                }
                if (!fwrite($handle, $content)) {
                        return false;
                }
                fclose($handle);
                return true;
        }

}

class jdbg {
        const IS_EMPTY = '__jdbg_isempty';
        const IS_NOTEMPTY = '__jdbg_isnotempty';
        const EXIT_DONT = 0;
        const EXIT_ALWAYS = 1;

        public static $LOG_FILE;
        public static $JDEBUG_OVERRIDE = true;
        public static $MODE = 'pre';
        public static $LIB = 'krumo';
        public static $INTS = true;

        /**
         *
         * @param mixed $val
         * @param mixed $var_val
         * @param <type> $var_cond
         * @param <type> $exit_mode
         */
        public static function p($val, $var_val = null, $var_cond = null, $exit_mode = jdbg::EXIT_DONT) {
                jdbg::pf($val, jdbg::$MODE, $var_val, $var_cond, $exit_mode);
        }

        public static function pe($val, $var_val = null, $var_cond = null) {
                jdbg::p($val, $var_val, $var_cond, jdbg::EXIT_ALWAYS);
        }

        public static function px($val, $var_val = null, $var_cond = null, $exit_mode = jdbg::EXIT_DONT) {
                jdbg::pf($val, jdbg::$MODE, $var_val, $var_cond, $exit_mode, true);
        }

        public static function pex($val, $var_val = null, $var_cond = null) {
                jdbg::px($val, $var_val, $var_cond, jdbg::EXIT_ALWAYS);
        }

        /**
         * takes as parameter various conditions that need to be met to print out $val with the given printing modes
         *
         * conditions are set as:
         *  actual value equivalence - simply give the value
         *  reg. expression - syntax: re:pattern
         *  check if value is empty or not - use jdbg::IS_EMPTY or jdbg::IS_NOTEMPTY
         *
         * available modes are:
         * pre
         *  pre formatted
         * area
         *  within textarea
         * dump
         *  using var_dump (good enough if xdebug is available)
         * krumo
         *  using krumo (included in this pkg)
         *  syntax: krumo[ krumo-function]?
         * file
         *  dump to a log file
         *  syntax: file[ reset][ ts], default appends to the log file and reset resets the file
         *
         * @@todo: ensure that xdebug is installed before printing out stack trace
         */
        public static function pf($val, $mode = 'pre', $var_val = null, $var_cond = null, $exit_mode = jdbg::EXIT_DONT, $xdebug = false) {
                if (!JDEBUG && !jdbg::$JDEBUG_OVERRIDE)
                        return;

                if (isset($var_cond)) {
                        $empty = !isset($var_val) || (empty($var_val) && $var_val != 0);
                        
                        if ($var_cond === jdbg::IS_EMPTY && !$empty) {
                                return;
                        } else if ($var_cond === jdbg::IS_NOTEMPTY && $empty) {
                                return;
                        } else if (preg_match('/re\:(.+)/', $var_cond, $m)) {
                                if (!preg_match('/' . $m[1] . '/', $var_val)) {
                                        return;
                                }
                        } else if (isset($var_val) && $var_val !== $var_cond) {
                                return;
                        }
                }
                
                $msg = '';
                if ($mode == 'log') $mode = 'file';
                
                if ($xdebug && in_array('xdebug', get_loaded_extensions()))
                        if ($mode == 'email' || strpos($mode, 'file') !== false)
                                $msg = xdebug_get_function_stack (JText::_('From jdbg'));
                        else 
                                xdebug_print_function_stack(JText::_('From jdbg'));

                if ($mode == 'pre') {
                        echo "<pre>";
                        print_r($val);
                        echo "</pre>";
                } else if ($mode == 'area') {
                        echo "<textarea>";
                        print_r($val);
                        echo "</textarea>";
                } else if ($mode == 'dump') {
                        var_dump($val);
                } else if (preg_match('/^krumo(?:\s+(.*))?$/', $mode, $m)) {
                        static $krumos = array('backtrace', 'classes', 'conf', 'cookie', 'defines', 'env', 'extensions', 'functions', 'get', 'headers', 'includes', 'interfaces', 'path', 'phpini', 'post', 'request', 'server', 'session');

                        $mtd = count($m) == 1 ? 'dump' : $m[1];

                        if (isset($mtd) && in_array($mtd, $krumos)) {
                                call_user_func(array('krumo', $mtd), $val);
                        } else if ($mtd == 'dump') {
                                if (self::$LIB == 'krumo')
                                        krumo($val);
                                else
                                        d($val);
                        }
                } else if ($mode == 'firephp') {
                        FB::log($val);
                } else if ($mode == 'email') {
                        $query = 'SELECT email, name FROM #__users WHERE gid = 25 AND sendEmail = 1';
                        $db = JFactory::getDBO();
                        $db->setQuery($query);
                        
                        if (!$db->query()) {
                                JError::raiseError(500, $db->stderr(true));
                                return;
                        }
                        
                        $adminRows = $db->loadObjectList();
                        
                        jimport('joomla.utilities.date');
                        $time = new JDate();
                        $time = $time->toFormat();
                        
                        $uri = JURI::getInstance(JURI::base());
                        $uri = $uri->toString(array('host', 'port'));
                        
                        $val = self::_val($val);
                        $msg .= $val;
                        
                        $subject = JText::sprintf('PLG_JDBG_EMAIL_SUBJECT', $uri, $time);
                        $body = JText::sprintf('PLG_JDBG_EMAIL_BODY', $msg);

                        $config = JFactory::getConfig();
                        $from = $config->getValue('mailfrom');
                        $fromName = $config->getValue('fromname');
                        
                        foreach ($adminRows as $adminRow) {
                                JUtility::sendMail($from, $fromName, $adminRow->email, $subject, $body);
                        }
                        
                        // don't allow ending if in email mode
                        return;
                } else {
                        $mode = explode(' ', $mode);
                        static $alreadyReset = false;
                        
                        if (in_array('file', $mode)) {
                                if (!isset(jdbg::$LOG_FILE)) {
                                        jdbg::$LOG_FILE = JPATH_SITE . DS . 'tmp' . DS . 'jdbg.log';
                                }

                                $isreset = in_array('reset', $mode);
                                
                                $val = self::_val($msg) . (!empty($msg) ? "\n" : "") . self::_val($val);
                                
                                if (jdbg::$INTS) {
                                        $val = date('Y-m-d H:i:s', time()) . "//" . jdbg::t(true, false) . "\n" . $val;
                                }
                                
                                if ($isreset && !$alreadyReset) {
                                        $alreadyReset = true;
                                        file_put_contents(jdbg::$LOG_FILE, $val."\n");
                                } else {
                                        file_put_contents(jdbg::$LOG_FILE, $val."\n", FILE_APPEND);
                                }
                        }
                }

                if ($exit_mode == jdbg::EXIT_ALWAYS) {
                        exit(0);
                }
        }
        
        private static function _val($val) {
                if (is_object($val) || is_array($val)) {
                        ob_start();
                        print_r($val);
                        $val = ob_get_contents();
                        ob_end_clean();   
                }
                return $val;
        }

        /**
         * First call initiates timing
         * Second call prints out the measured timing since initial call and resets initial time
         *  unless continue_measuring is set to true, in which case no reset is done.
         */
        public static function t($continue_measuring = false, $print = false) {
                static $time;

                list($usec, $sec) = explode(" ", microtime());
                $ctime = ((float) $usec + (float) $sec);

                if (isset($time)) {
                        if ($print) {
                                jdbg::p('Execution time is: ' . ($ctime - $time));
                        } else {
                                return ($ctime - $time);
                        }

                        if (!$continue_measuring)
                                $time = null;
                } else {
                        $time = $ctime;
                }
        }

}
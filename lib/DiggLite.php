<?php
/**
 * Digg Lite
 * 
 * @author    Jeff Hodsdon <jeff@digg.com>
 * @author    Bill Shupp <hostmaster@shupp.org> 
 * @copyright 2009 Digg, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php FreeBSD
 * @link      http://digglite.com
 */

require_once 'HTTP/OAuth/Consumer.php';
require_once 'Services/Digg2.php';
require_once 'DiggLite/Exception.php';
require_once 'DiggLite/Cache.php';
require_once 'DiggLite/View.php';

/**
 * Digg Lite
 * 
 * @author    Jeff Hodsdon <jeff@digg.com>
 * @author    Bill Shupp <hostmaster@shupp.org> 
 * @copyright 2009 Digg, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php FreeBSD
 * @link      http://digglite.com
 */
class DiggLite
{

    /**
     * Configuration options
     *
     * @var array $options Configuration options
     */
    static public $options = array();

    /**
     * cache 
     * 
     * @var Cache Instance of cache
     */
    protected $cache = null;

    /**
     * Digg
     * 
     * @var Services_Digg2 Instance of the Services_Digg2 library
     */
    protected $digg = null;

    /**
     * View object
     *
     * The view object $view Outputs the template
     *
     * @var DiggLite_View Instance of the view
     */
    protected $view = null;

    /**
     * Digg topics
     *
     * @var array $topics Topics from the Digg API
     * @see self::getTopics()
     */
    protected $topics = array();

    /**
     * Constructor
     *
     * Sets up instances of Services_Digg2, DiggLite_View, Cache, and
     * HTTP_OAuth_Consumer using data from the current session and config
     * options.
     *
     * @return void
     */
    public function __construct()
    {
        // Some error checking
        if (!isset(self::$options['apiKey'])) {
            throw new DiggLite_Exception('apiKey option is missing');
        }

        if (!isset(self::$options['apiSecret'])) {
            throw new DiggLite_Exception('apiKey option is missing');
        }

        // Instantiate Services_Digg2
        $this->digg = new Services_Digg2();
        $this->digg->setURI(self::$options['apiUrl']);

        // Instantiate a View object
        $this->view = new DiggLite_View();

        // Instantiate Cache
        if (!isset(self::$options['cache'])) {
            $this->cache = DiggLite_Cache::factory('CacheLite');
            return;
        }

        // Cache options must be an array
        if (!isset(self::$options['cacheOptions'])) {
            self::$options['cacheOptions'] = array();
        }

        // Create the cache instance
        $this->cache = DiggLite_Cache::factory(self::$options['cache'],
                                               self::$options['cacheOptions']);

        // Create the instance of HTTP_OAuth_Consumer
        $this->oauth = new HTTP_OAuth_Consumer(self::$options['apiKey'],
                                               self::$options['apiSecret']);

        // Add token and token secret to HTTP_OAuth_Consumer
        if (!empty($_SESSION['authorized'])) {
            $this->oauth->setToken($_SESSION['oauth_token']);
            $this->oauth->setTokenSecret($_SESSION['oauth_token_secret']);

            // Accept into Services_Digg2 for OAuth requests
            $this->digg->accept($this->oauth);

            if (!isset($_SESSION['actions'])) {
                $_SESSION['actions'] = array();
            }
        }
    }

    /**
     * Main
     *
     * Generate everything needed to display DiggLite's list page.
     *
     * @return void
     */
    public function main()
    {
        if (empty($_SESSION['authorized'])) {
            $this->view->authURL = $this->getAuthURL();
        } else {
            $this->view->user = $this->digg->user->getInfo()->user->username;
            $this->view->actions = $_SESSION['actions'];
        }
        if (isset($_POST['event']) && $_POST['event'] == 'setTopic') {
            $this->setTopic();
        }

        $this->view->topics = $this->getTopics();
        $this->getSelectedTopic();
        $this->getStories();

        return $this->view->render('list.tpl');
    }

    /**
     * Callback
     *
     * Upon a callback do this. Retreive an the access token from the API,
     * store it in the session, and redirect the browser to the list.
     *
     * @return void
     */
    public function callback()
    {
        $this->oauth->setToken($_SESSION['oauth_token']);
        $this->oauth->setTokenSecret($_SESSION['oauth_token_secret']);
        $this->oauth->getAccessToken(self::$options['accessTokenUrl'], $_GET['oauth_verifier'],
            array('method' => 'oauth.getAccessToken'), 'POST');
        $this->digg->accept($this->oauth);

        $_SESSION['oauth_token']        = $this->oauth->getToken();
        $_SESSION['oauth_token_secret'] = $this->oauth->getTokenSecret();
        $_SESSION['authorized']         = 1;
        $_SESSION['actions'] = array();

        session_write_close();

        return header('Location: /');
    }

    /**
     * Digg a story
     *
     * This is ran on the ajax call do digg a story.
     *
     * @return void
     */
    public function digg()
    {
        header('Content-Type: application/json');
        try {
            if (!isset($_POST['story_id'])) {
                throw new Exception('No story id in request');
            }

            $res = $this->digg->story->digg(array('story_id' => $_POST['story_id']));
            $_SESSION['actions'][$_POST['story_id']] = 'dugg';
        } catch (Exception $e) {
            return print(json_encode(array('error' => $e->getMessage())));
        }

        if (empty($res->digg->status)) {
            return print(json_encode(array('error' => 'Ack! Digg on story was not successful')));
        }

        return print(json_encode($res));
    }

    /**
     * Bury a story
     *
     * This is ran on the ajax call do bury a story.
     *
     * @return void
     */
    public function bury()
    {
        header('Content-Type: application/json');
        try {
            if (!isset($_POST['story_id'])) {
                throw new Exception('No story id in request');
            }

            $res = $this->digg->story->hide(array('story_id' => $_POST['story_id']));
            $_SESSION['actions'][$_POST['story_id']] = 'buried';
        } catch (Exception $e) {
            return print(json_encode(array('error' => $e->getMessage())));
        }

        if (empty($res->hide->status)) {
            return print(json_encode(array('error' => 'Ack! Bury on story was not successful')));
        }

        return print(json_encode($res));
    }

    /**
     * Error handler
     *
     * @param Exception $e The Exception that occured
     *
     * @return void
     */
    public function error(Exception $e)
    {
        if (empty(self::$options['debug'])) {
            echo $e->getMessage();
        } else {
            echo $e->getTraceAsString(); 
        }
    }

    /**
     * Get an authorize url
     *
     * Generate the url the user goes to in order to authorize this application
     *
     * @return void
     */
    public function getAuthURL()
    {
        $this->oauth->getRequestToken(self::$options['requestTokenUrl'], self::$options['callback'],
            array('method' => 'oauth.getRequestToken'), 'POST');

        $_SESSION['oauth_token']        = $this->oauth->getToken();
        $_SESSION['oauth_token_secret'] = $this->oauth->getTokenSecret();
        $_SESSION['authorized']         = 0;

        return $this->oauth->getAuthorizeURL(self::$options['authorizeUrl']);
    }

    /**
     * Get the stories to display
     *
     * Does logic to determine if a topic has been selected or not.
     *
     * @return void
     */
    protected function getStories()
    {
        $this->digg->setURI(self::$options['apiUrl']);
        $storiesKey = md5('stories' . $this->view->selectedTopic);

        $stories = $this->cache->get($storiesKey);
        if (!$stories) {
            $params = array('limit' => 50);
            if ($this->view->selectedTopic) {
                $params['topic'] = $this->view->selectedTopic;
            }
            $stories = $this->digg->story->getTopNews($params)->stories;
            foreach ($stories as $story) {
                $story->since = $this->getSinceTime($story->promote_date);
                $story->story_id = str_replace(':', '_', $story->story_id);
            }

            $this->cache->set($storiesKey, $stories, 30);
        }

        $this->view->stories = $stories;
    }

    /**
     * Get a formated since time
     *
     * @param int $time Time from
     *
     * @return string Easy to read since time
     */
    protected function getSinceTime($time)
    {
        $seconds = time() - $time;
        if ($seconds < 0) {
            $timeString = '5 sec ago';
        } elseif ($seconds < 60) {
            $timeString = $seconds.' sec ago';
        } elseif ($seconds < 120) {
            $timeString = '1 min ago';
        } elseif ($seconds < 3600) {
            $timeString = floor($seconds/60).' min ago';
        } elseif ($seconds < 3660) {
            $timeString = '1 hr ago';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds/3600);
            $minutes = floor(($seconds-$hours*3600)/60);
            if ($minutes == 0) {
                $timeString = $hours.' hr ago';
            } elseif ($minutes == 1) {
                if ($hours > 1) {
                    $timeString = $hours.' hr 1 min ago';
                } else {
                    $timeString = $hours.' hr 1 min ago';
                }
            } else {
                if ($hours > 1) {
                    $timeString = $hours.' hr '.$minutes.' min ago';
                } else {
                    $timeString = $hours.' hr '.$minutes.' min ago';
                }
            }
        } else {
            $timeString = 'a long time ago';
        }

        return $timeString;
    }

    /**
     * Determine selected topic and set that topic on the view 
     * 
     * @return void
     */
    protected function getSelectedTopic()
    {
        $this->view->selectedTopic = null;
        $this->view->topicTitle    = 'All Topics';
        if (isset($_SESSION['selectedTopic'])) {
            $this->view->selectedTopic = $_SESSION['selectedTopic'];
            foreach ($this->view->topics as $topic) {
                if ($topic->short_name == $_SESSION['selectedTopic']) {
                    $this->view->topicTitle = $topic->name;
                }
            }
        }
    }

    /**
     * Set the users selected topic
     *
     * @return void
     */
    public function setTopic()
    {
        if (!isset($_POST['topic'])) {
            return;
        }

        if ($_POST['topic'] == 'all') {
            unset($_SESSION['selectedTopic']);
            return;
        }

        foreach ($this->getTopics() as $topic) {
            if ($topic->short_name == $_POST['topic']) {
                $_SESSION['selectedTopic'] = $_POST['topic'];
                $_SESSION['topicTopic']    = $topic->name;
            }
        }
    }

    /**
     * Get topics from the API for the drop down
     *
     * @return array Topic information
     */
    protected function getTopics()
    {
        $topicsKey = md5('topics');
        $topics    = $this->cache->get($topicsKey);
        if (!$topics) {
            $this->digg->setURI(self::$options['apiUrl']);
            $topics = $this->digg->topic->getAll()->topics;
            $this->cache->set($topicsKey, $topics);
        }

        return $topics;
    }

}


?>

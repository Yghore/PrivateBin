<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use PHPUnit\Framework\TestCase;
use PrivateBin\Controller;
use PrivateBin\Data\Filesystem;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\Persistence\TrafficLimiter;
use PrivateBin\Request;

/**
 * @internal
 * @coversNothing
 */
final class ControllerTest extends TestCase
{
    protected $_data;

    protected $_path;

    protected function setUp(): void
    {
        // Setup Routine
        $this->_path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'privatebin_data';
        $this->_data = new Filesystem(['dir' => $this->_path]);
        ServerSalt::setStore($this->_data);
        TrafficLimiter::setStore($this->_data);
        $this->reset();
    }

    protected function tearDown(): void
    {
        // Tear Down Routine
        unlink(CONF);
        Helper::confRestore();
        Helper::rmDir($this->_path);
    }

    public function reset()
    {
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        if ($this->_data->exists(Helper::getPasteId())) {
            $this->_data->delete(Helper::getPasteId());
        }
        $options = parse_ini_file(CONF_SAMPLE, true);
        $options['model_options']['dir'] = $this->_path;
        Helper::createIniFile(CONF, $options);
    }

    /**
     * @runInSeparateProcess
     */
    public function testView()
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertStringContainsString(
            '<title>PrivateBin</title>',
            $content,
            'outputs title correctly'
        );
        static::assertStringNotContainsString(
            'id="shortenbutton"',
            $content,
            'doesn\'t output shortener button'
        );
        static::assertMatchesRegularExpression(
            '# href="https://'.preg_quote($_SERVER['HTTP_HOST']).'/">switching to HTTPS#',
            $content,
            'outputs configured https URL correctly'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testViewLanguageSelection()
    {
        $options = parse_ini_file(CONF, true);
        $options['main']['languageselection'] = true;
        Helper::createIniFile(CONF, $options);
        $_COOKIE['lang'] = 'de';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertStringContainsString(
            '<title>PrivateBin</title>',
            $content,
            'outputs title correctly'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testViewForceLanguageDefault()
    {
        $options = parse_ini_file(CONF, true);
        $options['main']['languageselection'] = false;
        $options['main']['languagedefault'] = 'fr';
        Helper::createIniFile(CONF, $options);
        $_COOKIE['lang'] = 'de';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertStringContainsString(
            '<title>PrivateBin</title>',
            $content,
            'outputs title correctly'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testViewUrlShortener()
    {
        $shortener = 'https://shortener.example.com/api?link=';
        $options = parse_ini_file(CONF, true);
        $options['main']['urlshortener'] = $shortener;
        Helper::createIniFile(CONF, $options);
        $_COOKIE['lang'] = 'de';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#id="shortenbutton"[^>]*data-shortener="'.preg_quote($shortener).'"#',
            $content,
            'outputs configured shortener URL correctly'
        );
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionCode 2
     */
    public function testConf()
    {
        file_put_contents(CONF, '');
        $this->expectException(Exception::class);
        $this->expectExceptionCode(2);
        new Controller();
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreate()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidTimelimit()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson(2, ['expire' => 25]);
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        TrafficLimiter::canPass();
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidSize()
    {
        $options = parse_ini_file(CONF, true);
        $options['main']['sizelimit'] = 10;
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateProxyHeader()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['header'] = 'X_FORWARDED_FOR';
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '::2';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateDuplicateId()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateValidExpire()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $time = time();
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
        static::assertGreaterThanOrEqual($time + 300, $paste['meta']['expire_date'], 'time is set correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateValidExpireWithDiscussion()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $time = time();
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
        static::assertGreaterThanOrEqual($time + 300, $paste['meta']['expire_date'], 'time is set correctly');
        static::assertSame(1, $paste['adata'][2], 'discussion is enabled');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidExpire()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson(2, ['expire' => 'foo']);
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_data->read($response['id']);
        static::assertSame(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidBurn()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPastePost();
        $paste['adata'][3] = 'neither 1 nor 0';
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode($paste));
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidOpenDiscussion()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPastePost();
        $paste['adata'][2] = 'neither 1 nor 0';
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode($paste));
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * In some webserver setups (found with Suhosin) overly long POST params are
     * silently removed, check that this case is handled.
     *
     * @runInSeparateProcess
     */
    public function testCreateBrokenUpload()
    {
        $paste = substr(Helper::getPasteJson(), 0, -10);
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste does not exists before posting data');
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateTooSoon()
    {
        $paste = Helper::getPasteJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        ob_end_clean();
        $this->_data->delete(Helper::getPasteId());
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidFormat()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, Helper::getPasteJson(1));
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateComment()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $comment = Helper::getCommentJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $comment);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertTrue($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), $response['id']), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateInvalidComment()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $comment = Helper::getCommentPost();
        $comment['parentid'] = 'foo';
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode($comment));
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getCommentId()), 'comment exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateCommentDiscussionDisabled()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $comment = Helper::getCommentJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $comment);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $paste = Helper::getPaste();
        $paste['adata'][2] = 0;
        $this->_data->create(Helper::getPasteId(), $paste);
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getCommentId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateCommentInvalidPaste()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $comment = Helper::getCommentJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $comment);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertFalse($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getCommentId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreateDuplicateComment()
    {
        $options = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        $this->_data->createComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getPasteId(), Helper::getComment());
        static::assertTrue($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getPasteId()), 'comment exists before posting data');
        $comment = Helper::getCommentJson();
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $comment);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertTrue($this->_data->existsComment(Helper::getPasteId(), Helper::getPasteId(), Helper::getPasteId()), 'paste exists after posting data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadInvalidId()
    {
        $_SERVER['QUERY_STRING'] = 'foo';
        $_GET['foo'] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertSame('Invalid paste ID.', $response['message'], 'outputs error message');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadNonexisting()
    {
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertSame('Paste does not exist, has expired or has been deleted.', $response['message'], 'outputs error message');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadExpired()
    {
        $expiredPaste = Helper::getPaste(2, ['expire_date' => 1344803344]);
        $this->_data->create(Helper::getPasteId(), $expiredPaste);
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs error status');
        static::assertSame('Paste does not exist, has expired or has been deleted.', $response['message'], 'outputs error message');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadBurn()
    {
        $paste = Helper::getPaste();
        $paste['adata'][3] = 1;
        $this->_data->create(Helper::getPasteId(), $paste);
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs success status');
        static::assertSame(Helper::getPasteId(), $response['id'], 'outputs data correctly');
        static::assertStringEndsWith('?'.$response['id'], $response['url'], 'returned URL points to new paste');
        static::assertSame($paste['ct'], $response['ct'], 'outputs ct correctly');
        static::assertSame($paste['adata'][1], $response['adata'][1], 'outputs formatter correctly');
        static::assertSame($paste['adata'][2], $response['adata'][2], 'outputs opendiscussion correctly');
        static::assertSame($paste['adata'][3], $response['adata'][3], 'outputs burnafterreading correctly');
        static::assertSame($paste['meta']['created'], $response['meta']['created'], 'outputs created correctly');
        static::assertSame(0, $response['comment_count'], 'outputs comment_count correctly');
        static::assertSame(0, $response['comment_offset'], 'outputs comment_offset correctly');
        // by default it will be deleted instantly after it is read
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste exists after reading');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadJson()
    {
        $paste = Helper::getPaste();
        $this->_data->create(Helper::getPasteId(), $paste);
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs success status');
        static::assertSame(Helper::getPasteId(), $response['id'], 'outputs data correctly');
        static::assertStringEndsWith('?'.$response['id'], $response['url'], 'returned URL points to new paste');
        static::assertSame($paste['ct'], $response['ct'], 'outputs ct correctly');
        static::assertSame($paste['adata'][1], $response['adata'][1], 'outputs formatter correctly');
        static::assertSame($paste['adata'][2], $response['adata'][2], 'outputs opendiscussion correctly');
        static::assertSame($paste['adata'][3], $response['adata'][3], 'outputs burnafterreading correctly');
        static::assertSame($paste['meta']['created'], $response['meta']['created'], 'outputs created correctly');
        static::assertSame(0, $response['comment_count'], 'outputs comment_count correctly');
        static::assertSame(0, $response['comment_offset'], 'outputs comment_offset correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadOldSyntax()
    {
        $paste = Helper::getPaste(1);
        $paste['meta'] = [
            'syntaxcoloring' => true,
            'postdate' => $paste['meta']['postdate'],
            'opendiscussion' => $paste['meta']['opendiscussion'],
        ];
        $this->_data->create(Helper::getPasteId(), $paste);
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs success status');
        static::assertSame(Helper::getPasteId(), $response['id'], 'outputs data correctly');
        static::assertStringEndsWith('?'.$response['id'], $response['url'], 'returned URL points to new paste');
        static::assertSame($paste['data'], $response['data'], 'outputs data correctly');
        static::assertSame('syntaxhighlighting', $response['meta']['formatter'], 'outputs format correctly');
        static::assertSame($paste['meta']['postdate'], $response['meta']['postdate'], 'outputs postdate correctly');
        static::assertSame($paste['meta']['opendiscussion'], $response['meta']['opendiscussion'], 'outputs opendiscussion correctly');
        static::assertSame(0, $response['comment_count'], 'outputs comment_count correctly');
        static::assertSame(0, $response['comment_offset'], 'outputs comment_offset correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testReadBurnAfterReading()
    {
        $burnPaste = Helper::getPaste();
        $burnPaste['adata'][3] = 1;
        $this->_data->create(Helper::getPasteId(), $burnPaste);
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(0, $response['status'], 'outputs status');
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste successfully deleted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDelete()
    {
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $paste = $this->_data->read(Helper::getPasteId());
        $_GET['pasteid'] = Helper::getPasteId();
        $_GET['deletetoken'] = hash_hmac('sha256', Helper::getPasteId(), $paste['meta']['salt']);
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="status"[^>]*>.*Paste was properly deleted\.#s',
            $content,
            'outputs deleted status correctly'
        );
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste successfully deleted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteInvalidId()
    {
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        $_GET['pasteid'] = 'foo';
        $_GET['deletetoken'] = 'bar';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="errormessage"[^>]*>.*Invalid paste ID\.#s',
            $content,
            'outputs delete error correctly'
        );
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists after failing to delete data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteInexistantId()
    {
        $_GET['pasteid'] = Helper::getPasteId();
        $_GET['deletetoken'] = 'bar';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="errormessage"[^>]*>.*Paste does not exist, has expired or has been deleted\.#s',
            $content,
            'outputs delete error correctly'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteInvalidToken()
    {
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        $_GET['pasteid'] = Helper::getPasteId();
        $_GET['deletetoken'] = 'bar';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="errormessage"[^>]*>.*Wrong deletion token\. Paste was not deleted\.#s',
            $content,
            'outputs delete error correctly'
        );
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists after failing to delete data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteInvalidBurnAfterReading()
    {
        $this->_data->create(Helper::getPasteId(), Helper::getPaste());
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $file = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode([
            'deletetoken' => 'burnafterreading',
        ]));
        Request::setInputStream($file);
        $_SERVER['QUERY_STRING'] = Helper::getPasteId();
        $_GET[Helper::getPasteId()] = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        static::assertSame(1, $response['status'], 'outputs status');
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists after failing to delete data');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteExpired()
    {
        $expiredPaste = Helper::getPaste(2, ['expire_date' => 1000]);
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste does not exist before being created');
        $this->_data->create(Helper::getPasteId(), $expiredPaste);
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $_GET['pasteid'] = Helper::getPasteId();
        $_GET['deletetoken'] = 'does not matter in this context, but has to be set';
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="errormessage"[^>]*>.*Paste does not exist, has expired or has been deleted\.#s',
            $content,
            'outputs error correctly'
        );
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste successfully deleted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteMissingPerPasteSalt()
    {
        $paste = Helper::getPaste();
        unset($paste['meta']['salt']);
        $this->_data->create(Helper::getPasteId(), $paste);
        static::assertTrue($this->_data->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $_GET['pasteid'] = Helper::getPasteId();
        $_GET['deletetoken'] = hash_hmac('sha256', Helper::getPasteId(), ServerSalt::get());
        ob_start();
        new Controller();
        $content = ob_get_contents();
        ob_end_clean();
        static::assertMatchesRegularExpression(
            '#<div[^>]*id="status"[^>]*>.*Paste was properly deleted\.#s',
            $content,
            'outputs deleted status correctly'
        );
        static::assertFalse($this->_data->exists(Helper::getPasteId()), 'paste successfully deleted');
    }
}

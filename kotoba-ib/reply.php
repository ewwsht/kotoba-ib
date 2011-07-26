<?php
/* *******************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************/

/*
 * Reply script.
 *
 * Parameters:
 * MAX_FILE_SIZE - maximum size of uploaded file in bytes (see config.default).
 * name - name.
 * subject - subject.
 * text - text.
 * file (optional) - uploaded file.
 * spoiler (optional) - attachment is spoiler.
 * use_oekaki (optional) - use drawn picture as attachment.
 * macrochan_tag (optional) - macrochan tag name.
 * youtube_video_code (optional) - code of youtube video.
 * captcha_code (optional) - captcha code.
 * animaptcha_code (optional) - animaptcha word.
 * password - password.
 * goto - redirection.
 * sage - sage flag.
 * t - thread id.
 */

require_once 'config.php';
require_once Config::ABS_PATH . '/lib/exceptions.php';
require_once Config::ABS_PATH . '/lib/errors.php';
require_once Config::ABS_PATH . '/lib/db.php';
require_once Config::ABS_PATH . '/lib/misc.php';
require_once Config::ABS_PATH . '/lib/popdown_handlers.php';
require_once Config::ABS_PATH . '/lib/upload_handlers.php';
require_once Config::ABS_PATH . '/lib/mark.php';
require_once Config::ABS_PATH . '/lib/latex_render.php';

try {
    // Initialization.
    kotoba_session_start();
    if (Config::LANGUAGE != $_SESSION['language']) {
        require Config::ABS_PATH . "/locale/{$_SESSION['language']}/exceptions.php";
    }
    locale_setup();
    $smarty = new SmartyKotobaSetup();

    // Check if client banned.
    if (!isset($_SERVER['REMOTE_ADDR'])
            || ($ip = ip2long($_SERVER['REMOTE_ADDR'])) === FALSE) {

        throw new RemoteAddressException();
    }
    if ( ($ban = bans_check($ip)) !== false) {
        $smarty->assign('ip', $_SERVER['REMOTE_ADDR']);
        $smarty->assign('reason', $ban['reason']);
        session_destroy();
        DataExchange::releaseResources();
        die($smarty->fetch('banned.tpl'));
    }

    $thread_id = threads_check_id($_POST['t']);
    $thread = threads_get_changeable_by_id($thread_id, $_SESSION['user']);
    if ($thread === 1) {

        // Cleanup.
        DataExchange::releaseResources();

        $ERRORS['THREAD_NOT_ALLOWED']($smarty, $_SESSION['user'], $thread_id);
        exit(1);
    } else if ($thread === 2) {

        // Cleanup.
        DataExchange::releaseResources();

        $ERRORS['THREAD_NOT_FOUND_ID']($smarty, $thread_id);
        exit(1);
    }
    if ($thread['archived']) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['THREAD_ARCHIVED']($smarty, $thread['id']);
        exit(1);
    }
    if ($thread['closed']) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['THREAD_CLOSED']($smarty, $thread['id']);
        exit(1);
    }

    $board = $thread['board'];

    // Captcha.
    if (is_captcha_enabled($board)) {
        switch (Config::CAPTCHA) {
            case 'captcha':
                if (is_captcha_valid()) {
                    // Pass! Do smth?
                } else {
                    DataExchange::releaseResources();
                    $ERRORS['CAPTCHA']($smarty,
                                       mb_strtolower($_POST['captcha_code'],
                                                     Config::MB_ENCODING),
                                       $_SESSION['captcha_code']);
                }
                break;
            case 'animaptcha':
                if (is_animaptcha_valid()) {
                    // Pass! Do smth?
                } else {
                    DataExchange::releaseResources();
                    $ERRORS['CAPTCHA']($smarty,
                                       mb_strtolower($_POST['animaptcha_code'],
                                                     Config::MB_ENCODING),
                                       implode(',',
                                               $_SESSION['animaptcha_code']));
                }
                break;
            default:
                DataExchange::releaseResources();
                $ERRORS['CAPTCHA']($smarty, 'Unknown captcha type',
                                   'Unknown captcha type');
                break;
        }
    }

    // Redirection.
    $goto = null;
    $should_update_goto = false;
    if (isset($_POST['goto'])) {
        $goto = users_check_goto($_POST['goto']);
        if (!isset($_SESSION['goto']) || $_SESSION['goto'] != $goto) {
            $_SESSION['goto'] = $goto;
            $should_update_goto = true;
        }
    } else {
		throw new FormatException(FormatException::$messages['USER_GOTO']);
    }

    // Password.
	$password = null;
	$should_update_password = false;
	if (isset($_POST['password']) && $_POST['password'] != '') {
        $password = posts_check_password($_POST['password']);
        if (!isset($_SESSION['password']) || $_SESSION['password'] != $password) {
            $_SESSION['password'] = $password;
            $should_update_password = true;
        }
    }

    // Sage.
    $sage = $thread['sage'];
	if (isset($_POST['sage']) && $_POST['sage'] === 'sage') {
		$sage = 1;
    }

    // Name and tripcode.
    $name = null;
    $tripcode = null;
	if (!$board['force_anonymous']) {
		if (posts_check_name_size($_POST['name']) === 1) {

            // Cleanup
            DataExchange::releaseResources();

            $ERRORS['MAX_NAME_LENGTH']($smarty);
            exit(1);
        }
		$name = htmlentities($_POST['name'], ENT_QUOTES, Config::MB_ENCODING);
		$name = str_replace('\\', '\\\\', $name);
        if (posts_check_name_size($name) === 1) {

            // Cleanup
            DataExchange::releaseResources();

            $ERRORS['MAX_NAME_LENGTH']($smarty);
            exit(1);
        }
		$name = str_replace("\n", '', $name);
		$name = str_replace("\r", '', $name);
		if (posts_check_name_size($name) === 1) {

            // Cleanup
            DataExchange::releaseResources();

            $ERRORS['MAX_NAME_LENGTH']($smarty);
            exit(1);
        }
		$name_tripcode = calculate_tripcode($name);
        $_SESSION['name'] = $name;
		$name = $name_tripcode[0];
		$tripcode = $name_tripcode[1];
	}

    // Subject.
    if (posts_check_subject_size($_POST['subject']) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_SUBJECT_LENGTH']($smarty);
        exit(1);
    }
	$subject = htmlentities($_POST['subject'], ENT_QUOTES, Config::MB_ENCODING);
	$subject = str_replace('\\', '\\\\', $subject);
	if (posts_check_subject_size($subject) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_SUBJECT_LENGTH']($smarty);
        exit(1);
    }
	$subject = str_replace("\n", '', $subject);
	$subject = str_replace("\r", '', $subject);

    // Attachment type.
	$attachment_type = null;
    if ($thread['with_attachments'] || ($thread['with_attachments'] === null && $board['with_attachments'])) {
        if ((isset($_FILES['file']) && $_FILES['file']['error'] != UPLOAD_ERR_NO_FILE) || use_oekaki()) {
            if (!use_oekaki()) {
                switch ($_FILES['file']['error']) {
                    case UPLOAD_ERR_INI_SIZE:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_INI_SIZE']($smarty);
                        exit(1);
                        break;
                    case UPLOAD_ERR_FORM_SIZE:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_FORM_SIZE']($smarty);
                        exit(1);
                        break;
                    case UPLOAD_ERR_PARTIAL:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_PARTIAL']($smarty);
                        exit(1);
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_NO_TMP_DIR']($smarty);
                        exit(1);
                        break;
                    case UPLOAD_ERR_CANT_WRITE:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_CANT_WRITE']($smarty);
                        exit(1);
                        break;
                    case UPLOAD_ERR_EXTENSION:

                        // Cleanup
                        DataExchange::releaseResources();

                        $ERRORS['UPLOAD_ERR_EXTENSION']($smarty);
                        exit(1);
                        break;
                }
                $uploaded_file_path = $_FILES['file']['tmp_name'];
                $uploaded_file_name = $_FILES['file']['name'];
                $uploaded_file_size = $_FILES['file']['size'];
            } else {
                $uploaded_file_path = Config::ABS_PATH . "/shi/{$_SESSION['oekaki']['file']}";
                $uploaded_file_name = $_SESSION['oekaki']['file'];
                if ( ($uploaded_file_size = filesize($uploaded_file_path)) === false) {
                    throw new Exception('Cannot calculate filesize.');
                }
            }
            $uploaded_file_ext = get_extension($uploaded_file_name);
            $uploaded_file_ext = mb_strtolower($uploaded_file_ext, Config::MB_ENCODING);
            $upload_types = upload_types_get_by_board($board['id']);
            $found = false;
            $upload_type = null;
            foreach ($upload_types as $ut) {
                if ($ut['extension'] == $uploaded_file_ext) {
                    $found = true;
                    $upload_type = $ut;
                    break;
                }
            }
            if (!$found) {

                // Cleanup
                DataExchange::releaseResources();

                $ERRORS['UPLOAD_FILETYPE_NOT_SUPPORTED']($smarty,
                                                         $uploaded_file_ext);
                exit(1);
            }
            if ($upload_type['is_image']) {
                $attachment_type = Config::ATTACHMENT_TYPE_IMAGE;
                if (images_check_size($uploaded_file_size) === 1) {

                    // Cleanup
                    DataExchange::releaseResources();

                    $ERRORS['MIN_IMG_SIZE']($smarty);
                    exit(1);
                }
            } else {
                $attachment_type = Config::ATTACHMENT_TYPE_FILE;
            }
        } elseif (($board['enable_macro'] === null && Config::ENABLE_MACRO || $board['enable_macro'])
                  && isset($_POST['macrochan_tag'])
                  && $_POST['macrochan_tag'] != '') {
            $macrochan_tag['name'] = macrochan_tags_check($_POST['macrochan_tag']);
            $attachment_type = Config::ATTACHMENT_TYPE_LINK;
        } elseif (($board['enable_youtube'] === null && Config::ENABLE_YOUTUBE || $board['enable_youtube'])
                  && isset($_POST['youtube_video_code'])
                  && $_POST['youtube_video_code'] != '') {
            $youtube_video_code = videos_check_code($_POST['youtube_video_code']);
            if ($youtube_video_code === 1) {

                // Cleanup
                DataExchange::releaseResources();

                $ERRORS['MAX_FILE_LINK']($smarty);
                exit(1);
            }
            $attachment_type = Config::ATTACHMENT_TYPE_VIDEO;
        }
    }

    // Text.
    $text = $_POST['text'];
    if ($attachment_type === NULL && !preg_match('/\S/', $text)) {

        // Cleanup.
        DataExchange::releaseResources();

        $ERRORS['EMPTY_POST']($smarty);
    }
    if (posts_check_text_size($text) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_TEXT_LENGTH']($smarty);
        exit(1);
    }
    if (Config::ENABLE_SPAMFILTER) {
        $spam_filter = spamfilter_get_all();
        foreach ($spam_filter as $record) {
            if (preg_match("/{$record['pattern']}/", $text) > 0) {

                // Cleanup.
                DataExchange::releaseResources();

                $ERRORS['SPAM']($smarty);
            }
        }
    }
	$text = htmlentities($text, ENT_QUOTES, Config::MB_ENCODING);
    $text = transform($text);
	if (posts_check_text_size($text) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_TEXT_LENGTH']($smarty);
        exit(1);
    }
    if (Config::ENABLE_WORDFILTER) {
        $words = words_get_all_by_board($board['id']);
        foreach ($words as $word) {
            $text = preg_replace("#".$word['word']."#iu", $word['replace'], $text);
        }
    }
	$text = str_replace('\\', '\\\\', $text);
	if (posts_check_text_size($text) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_TEXT_LENGTH']($smarty);
        exit(1);
    }
	if (!posts_check_text($text)) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['NON_UNICODE']($smarty);
        exit(1);
    }
	posts_prepare_text($text, $board);
	if (posts_check_text_size($text) === 1) {

        // Cleanup
        DataExchange::releaseResources();

        $ERRORS['MAX_TEXT_LENGTH']($smarty);
        exit(1);
    }

    // Attachment.
    if ($attachment_type !== null) {
        if ($attachment_type == Config::ATTACHMENT_TYPE_FILE
                || $attachment_type == Config::ATTACHMENT_TYPE_IMAGE) {

            $file_hash = calculate_file_hash($uploaded_file_path);
            $file_exists = false;
            $same_attachments = null;
            switch ($board['same_upload']) {
                case 'once':
                    $same_attachments = attachments_get_same($board['id'], $_SESSION['user'], $file_hash);
                    if (count($same_attachments) > 0) {
                        $file_exists = true;
                    }
                    break;

                case 'no':
                    $same_attachments = attachments_get_same($board['id'], $_SESSION['user'], $file_hash);
                    if (count($same_attachments) > 0) {
                        $smarty->assign('show_control', is_admin() || is_mod());
                        $smarty->assign('boards', boards_get_visible($_SESSION['user']));
                        $smarty->assign('board', $board);
                        $smarty->assign('same_attachments', $same_attachments);
                        $smarty->display('same_attachments.tpl');
                        DataExchange::releaseResources();
                        exit(0);
                    }
                    break;

                case 'yes':
                    break;
                default:
                    throw new Exception('Unknown same uploads behaviour.');
                    break;
            }
            if (!$file_exists) {
                $file_names = create_filenames($upload_type['store_extension']);
            }
        }

        if ($attachment_type == Config::ATTACHMENT_TYPE_FILE) {
            if ($file_exists) {
                $attachment_id = $same_attachments[0]['id'];
            } else {
                $abs_file_path = Config::ABS_PATH . "/{$board['name']}/other/{$file_names[0]}";
                move_uploded_file($uploaded_file_path, $abs_file_path);
                $attachment_id = files_add($file_hash,
                                           $file_names[0],
                                           $uploaded_file_size,
                                           $upload_type['thumbnail_image'],
                                           Config::THUMBNAIL_WIDTH,
                                           Config::THUMBNAIL_HEIGHT);
            }
        } elseif ($attachment_type === Config::ATTACHMENT_TYPE_IMAGE) {
            if ($file_exists) {
                $attachment_id = $same_attachments[0]['id'];
            } else {
                $img_dimensions = image_get_dimensions($upload_type, $uploaded_file_path);
                if($img_dimensions['x'] < Config::MIN_IMGWIDTH && $img_dimensions['y'] < Config::MIN_IMGHEIGHT) {

                    // Cleanup
                    DataExchange::releaseResources();

                    $ERRORS['MIN_IMG_DIMENTIONS']($smarty);
                    exit(1);
                }
                $abs_img_path = Config::ABS_PATH . "/{$board['name']}/img/{$file_names[0]}";
                $abs_thumb_path = Config::ABS_PATH . "/{$board['name']}/thumb/{$file_names[1]}";
                if (!use_oekaki()) {
                    move_uploded_file($uploaded_file_path, $abs_img_path);
                    $thumb_dimensions = create_thumbnail($abs_img_path,
                                                         $abs_thumb_path,
                                                         $img_dimensions,
                                                         $upload_type,
                                                         Config::THUMBNAIL_WIDTH,
                                                         Config::THUMBNAIL_HEIGHT);
                } else {
                    copy_uploded_file($uploaded_file_path, $abs_img_path);
                    copy_uploded_file(Config::ABS_PATH . "/shi/{$_SESSION['oekaki']['thumbnail']}",
                                      $abs_thumb_path);
                    $thumb_dimensions = array('x' => Config::THUMBNAIL_WIDTH,
                                              'y' => Config::THUMBNAIL_HEIGHT);
                }
                $spoiler = (isset($_POST['spoiler']) && $_POST['spoiler'] == '1') ? true : false;
                $attachment_id = images_add($file_hash,
                                            $file_names[0],
                                            $img_dimensions['x'],
                                            $img_dimensions['y'],
                                            $uploaded_file_size,
                                            $file_names[1],
                                            $thumb_dimensions['x'],
                                            $thumb_dimensions['y'],
                                            $spoiler);
            }
        } elseif ($attachment_type == Config::ATTACHMENT_TYPE_LINK) {
            $macrochan_image = macrochan_images_get_random($macrochan_tag['name']);
            $macrochan_image['name'] = "http://12ch.ru/macro/index.php/image/{$macrochan_image['name']}";
            $macrochan_image['thumbnail'] = "http://12ch.ru/macro/index.php/thumb/{$macrochan_image['thumbnail']}";
            $attachment_id = links_add($macrochan_image['name'],
                                       $macrochan_image['width'],
                                       $macrochan_image['height'],
                                       $macrochan_image['size'],
                                       $macrochan_image['thumbnail'],
                                       $macrochan_image['thumbnail_w'],
                                       $macrochan_image['thumbnail_h']);
        } elseif ($attachment_type == Config::ATTACHMENT_TYPE_VIDEO) {
            $attachment_id = videos_add($youtube_video_code, 220, 182);
        } else {
            throw new CommonException('Not supported.');
        }
    }

    // Save post.
    date_default_timezone_set(Config::DEFAULT_TIMEZONE);
    $post = posts_add($board['id'],
                      $thread['id'],
                      $_SESSION['user'],
                      $password,
                      $name,
                      $tripcode,
                      ip2long($_SERVER['REMOTE_ADDR']),
                      $subject,
                      date(Config::DATETIME_FORMAT),
                      $text,
                      $sage);

    // Save attachment.
    if ($attachment_type !== null) {
        switch ($attachment_type) {
            case Config::ATTACHMENT_TYPE_FILE:
                posts_files_add($post['id'], $post['board'], $attachment_id, 0);
                break;
            case Config::ATTACHMENT_TYPE_IMAGE:
                posts_images_add($post['id'], $post['board'], $attachment_id,
                                 0);
                break;
            case Config::ATTACHMENT_TYPE_LINK:
                posts_links_add($post['id'], $attachment_id, 0);
                break;
            case Config::ATTACHMENT_TYPE_VIDEO:
                posts_videos_add($post['id'], $attachment_id, 0);
                break;
            default:
                throw new CommonException('Not supported.');
                break;
        }
    }

    // Update password and redirection.
	if ($_SESSION['user'] != Config::GUEST_ID && $should_update_password) {
        users_set_password($_SESSION['user'], $password);
    }
    if ($_SESSION['user'] != Config::GUEST_ID && $should_update_goto) {
        users_set_goto($_SESSION['user'], $goto);
    }

    // Popdown threads.
	foreach (popdown_handlers_get_all() as $popdown_handler) {
        if ($board['popdown_handler'] == $popdown_handler['id']) {
            $popdown_handler['name']($board['id']);
            break;
        }
    }

    unset($_SESSION['oekaki']);

    // Redirect.
    if ($_SESSION['goto'] == 't') {
        header('Location: ' . Config::DIR_PATH . "/{$board['name']}/{$thread['original_post']}/");
    } else {
        header('Location: ' . Config::DIR_PATH . "/{$board['name']}/");
    }

    if (ctype_digit($post['id'])) {
        echo "{$post['id']}";
    } else {
        throw new Exception('ID of new post is empty. Highload issue?');
    }

    // Cleanup.
    DataExchange::releaseResources();

    exit(0);
} catch (Exception $e) {
    $smarty->assign('msg', $e->__toString());
    DataExchange::releaseResources();
	if (isset($abs_file_path)) { // Удаление загруженного файла.
		@unlink($abs_file_path);
    }
    if (isset($abs_img_path)) { // Удаление загруженного файла.
        @unlink($abs_img_path);
    }
    if (isset($abs_thumb_path)) { // Удаление уменьшенной копии.
        @unlink($abs_thumb_path);
    }
    die($smarty->fetch('exception.tpl'));
}
?>

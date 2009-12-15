<?php
/*************************************
 * Этот файл является частью Kotoba. *
 * Файл license.txt содержит условия *
 * распространения Kotoba.		   *
 *************************************/
/*********************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************/
// Скрипт создания нитей.
require 'config.php';
require 'modules/errors.php';
require 'modules/lang/' . Config::LANGUAGE . '/errors.php';
require 'modules/db.php';
require 'modules/cache.php';
require 'modules/common.php';
require 'modules/popdown_handlers.php';
require 'modules/upload_handlers.php';
include 'securimage/securimage.php';
try
{
// 0. Инициализация.
	kotoba_session_start();
	locale_setup();
	$smarty = new SmartyKotobaSetup($_SESSION['language'], $_SESSION['stylesheet']);
	bans_check($smarty, ip2long($_SERVER['REMOTE_ADDR']));	// Возможно завершение работы скрипта.
// 1 Проверка входных параметров.
	$is_admin = false;
	if(in_array(Config::ADM_GROUP_NAME, $_SESSION['groups']))
		$is_admin = true;
	if(!$is_admin)
	{
		$securimage = new Securimage();
		if ($securimage->check($_POST['captcha_code']) == false)
			throw new CommonException(CommonException::$messages['CAPTCHA']);
	}
	$board = boards_get_specifed_change(boards_check_id($_POST['board']),
		$_SESSION['user']);
	if(isset($_POST['goto'])
		&& ($_POST['goto'] == 't' || $_POST['goto'] == 'b')
		&& $_POST['goto'] != $_SESSION['goto'])
			$_SESSION['goto'] = $_POST['goto'];
	if($board['with_files'])
	{
		check_upload_error($_FILES['file']['error']);
		$uploaded_file_size = $_FILES['file']['size'];
		$uploaded_file_path = $_FILES['file']['tmp_name'];
		$uploaded_file_name = $_FILES['file']['name'];
		$uploaded_file_ext = get_extension($uploaded_file_name);
		$upload_types = upload_types_get_board($board['id']);
		$found = false;
		$upload_type = null;
		foreach($upload_types as $ut)
			if($ut['extension'] == $uploaded_file_ext)
			{
				$found = true;
				$upload_type = $ut;
				break;
			}
		if(!$found)
			throw new UploadException(UploadException::$messages['UPLOAD_FILETYPE_NOT_SUPPORTED']);
		if($upload_type['is_image'])
			uploads_check_image_size($uploaded_file_size);
	}
	$pass = null;
	if(isset($_POST['rempass']) && $_POST['rempass'] != '')
	{
		$pass = posts_check_password($_POST['rempass']);
		if(!isset($_SESSION['rempass']) || $_SESSION['rempass'] != $pass)
			$_SESSION['rempass'] = $pass;
	}
	posts_check_text_size($_POST['text']);
	posts_check_subject_size($_POST['subject']);
	$name = null;
	$tripcode = null;
	if(!$board['force_anonymous'])
	{
		posts_check_name_size($_POST['name']);
		$name = htmlspecialchars($_POST['name'], ENT_QUOTES);
		$name = stripslashes($name);
		posts_check_name_size($name);
		$name_tripcode = calculate_tripcode($name);
		$name = $name_tripcode[0];
		$tripcode = $name_tripcode[1];
		posts_check_name_size($name);
	}
	else
		// Подписывать сообщения запрещено на этой доске.
		$name = '';
	$text = htmlspecialchars($_POST['text'], ENT_QUOTES);
	$subject = htmlspecialchars($_POST['subject'], ENT_QUOTES);
	$text = stripslashes($text);
	$subject = stripslashes($subject);
	posts_check_text_size($text);
	posts_check_subject_size($subject);
	if($board['with_files'])
	{
// 2. Подготовка файла к сохранению.
		$file_hash = calculate_file_hash($uploaded_file_path);
		$file_already_posted = false;
		$same_uploads = null;
		switch($board['same_upload'])
		{
			case 'no':
				$same_uploads = uploads_get_same($board['id'], $file_hash,
					$_SESSION['user']);
				if(count($same_uploads) > 0)
					// Terminate script!
					show_same_uploads($smarty, $board['name'], $same_uploads);
				break;
			case 'once':
				$same_uploads = uploads_get_same($board['id'], $file_hash,
					$_SESSION['user']);
				if(count($same_uploads) > 0)
					$file_already_posted = true;
				break;
			case 'yes':
			default:
				break;
		}
		if(!$file_already_posted)
		{
			if($upload_type['is_image'])
			{
				$img_dimensions = image_get_dimensions($upload_type,
					$uploaded_file_path);
				if($img_dimensions['x'] < Config::MIN_IMGWIDTH
					&& $img_dimensions['y'] < Config::MIN_IMGHEIGTH)
					throw new LimitException(LimitException::$messages['MIN_IMG_DIMENTIONS']);
			}
			$file_names = create_filenames($upload_type['store_extension']);
			// Directories of uploaded image and generated thumbnail.
			$abs_img_dir = Config::ABS_PATH . "/{$board['name']}/img";
			$virt_img_dir = Config::DIR_PATH . "/{$board['name']}/img";
			$abs_thumb_dir = Config::ABS_PATH . "/{$board['name']}/thumb";
			$virt_thumb_dir = Config::DIR_PATH . "/{$board['name']}/thumb";
			// Full path of uploaded image and generated thumbnail.
			$abs_img_path = "$abs_img_dir/{$file_names[0]}";
			$virt_img_path = "$virt_img_dir/{$file_names[0]}";
			if($upload_type['is_image'])
			{
				$abs_thumb_path = "$abs_thumb_dir/{$file_names[1]}";
				$virt_thumb_path = "$virt_thumb_dir/{$file_names[1]}";
			}
			else
				// TODO Actually it must be only a name not path.
				$virt_thumb_path = $upload_type['thumbnail_image'];
	// 3. Сохранение данных.
			move_uploded_file($uploaded_file_path, $abs_img_path);
			if($upload_type['is_image'])
			{
				$force = $upload_type['upload_handler_name'] === 'thumb_internal_png'
					? true : false;	// TODO Unhardcode handler name.
				$thumb_dimensions = create_thumbnail($abs_img_path,
					$abs_thumb_path, $img_dimensions, $upload_type,
					Config::THUMBNAIL_WIDTH, Config::THUMBNAIL_HEIGHT, $force);
				$upload_id = uploads_add($file_hash, $upload_type['is_image'],
					Config::LINK_TYPE_VIRTUAL, $file_names[0],
					$img_dimensions['x'], $img_dimensions['y'],
					$uploaded_file_size, $file_names[1], $thumb_dimensions['x'],
					$thumb_dimensions['y']);
			}
			else
				// 200 x 200 is default thumb dimensions for non images.
				// TODO Another link types support.
				$upload_id = uploads_add($file_hash, $upload_type['is_image'],
					Config::LINK_TYPE_VIRTUAL, $file_names[0], null, null,
					$uploaded_file_size, $virt_thumb_path,
					Config::THUMBNAIL_WIDTH, Config::THUMBNAIL_HEIGHT);

		}// Файл не был загружен ранее.
		else
			// Первый попавшийся из одинаковых файлов.
			$upload_id = $same_uploads[0]['id'];
	}
	// Создаём пустую нить.
	$thread = threads_add($board['id'], null, null, 0, null);
	date_default_timezone_set(Config::DEFAULT_TIMEZONE);
	$post = posts_add($board['id'], $thread['id'], $_SESSION['user'], $pass,
		$name, $tripcode, ip2long($_SERVER['REMOTE_ADDR']), $subject,
		date(Config::DATETIME_FORMAT), $text, null);
	// Закрепляем сообщение как оригинальное сообщение созданной пустой нити.
	threads_edit_originalpost($thread['id'], $post['number']);
	if($board['with_files'])
		posts_uploads_add($post['id'], $upload_id);
// 4. Запуск обработчика автоматического удаления нитей.
	foreach(popdown_handlers_get_all() as $popdown_handler)
		if($board['popdown_handler'] == $popdown_handler['id'])
		{
			$popdown_handler['name']($board['id']);
			break;
		}
	DataExchange::releaseResources();
// 5. Перенаправление.
	if($_SESSION['goto'] == 't')
		header('Location: ' . Config::DIR_PATH
			. "/{$board['name']}/{$post['number']}/");
	else
		header('Location: ' . Config::DIR_PATH . "/{$board['name']}/");
	exit;
}
catch(Exception $e)
{
	$smarty->assign('msg', $e->__toString());
	DataExchange::releaseResources();
	if(isset($abs_img_path))	// Удаление загруженного файла.
		@unlink($abs_img_path);
	if(isset($abs_thumb_path))	// Удаление уменьшенной копии.
		@unlink($abs_thumb_path);
	die($smarty->fetch('error.tpl'));
}
?>
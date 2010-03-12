<?php
/*************************************
 * Этот файл является частью Kotoba. *
 * Файл license.txt содержит условия *
 * распространения Kotoba.           *
 *************************************/
/*********************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************/
// Скрипт просмотра нити.
require 'config.php';
require 'modules/errors.php';
require 'modules/lang/' . Config::LANGUAGE . '/errors.php';
require 'modules/db.php';
require 'modules/cache.php';
require 'modules/common.php';
require 'modules/popdown_handlers.php';
require 'modules/events.php';
try
{
	kotoba_session_start();
	locale_setup();
	$smarty = new SmartyKotobaSetup($_SESSION['language'], $_SESSION['stylesheet']);
	bans_check($smarty, ip2long($_SERVER['REMOTE_ADDR']));	// Возможно завершение работы скрипта.
	header("Cache-Control: private");						// Fix for Firefox.
// Проверка входных параметров.
	$board_name = boards_check_name($_GET['board']);
	$thread_num = threads_check_number($_GET['thread']);
	$password = !isset($_SESSION['password']) || $_SESSION['password'] == null
		? '' : $_SESSION['password'];
	/*
	 * Доски нужны для вывода списка досок, поэтому получим все и среди них
	 * будем искать запрашиваемую.
	 */
	$boards = boards_get_visible($_SESSION['user']);
	$board = null;
	$found = false;
	foreach($boards as $b)
		if($b['name'] == $board_name)
		{
			$found = true;
			$board = $b;
			break;
		}
	if(!$found)
		throw new NodataException(sprintf(NodataException::$messages['BOARD_NOT_FOUND'],
				$board_name));
// Получение данных.
	$thread = threads_get_visible_by_id($board['id'], $thread_num,
		$_SESSION['user']);
	if($thread['archived'])
	{
		// Нить была заархивирована.
		DataExchange::releaseResources();
		header('Location: ' . Config::DIR_PATH . "/{$board['name']}/arch/"
			. "{$thread['original_post']}.html");
		exit;
	}
	if(threads_get_moderatable_by_id($thread['id'], $_SESSION['user']) === null)
		$is_moderatable = false;
	else
		$is_moderatable = true;
	$filter = function($posts_per_thread, $thread, $post)
	{
		static $recived = 0;
		if($thread['original_post'] == $post['number'])
			return true;
		$recived++;
		if($recived > $thread['posts_count'] - $posts_per_thread)
			return true;
		return false;
	};
	$posts = posts_get_visible_filtred_by_threads(array($thread),
		$_SESSION['user'], $filter, $thread['posts_count']);
	$posts_attachments = posts_attachments_get_by_posts($posts);
	$attachments = attachments_get_by_posts($posts);
	$ht_filter = function($user, $hidden_thread)
	{
		if($hidden_thread['user'] == $user)
			return true;
		return false;
	};
	$hidden_threads = hidden_threads_get_filtred_by_boards(array($board),
		$ht_filter, $_SESSION['user']);
	$upload_types = upload_types_get_board($board['id']);
	$macrochan_tags = array('orgasm_face');
// Формирование вывода.
	$smarty->assign('board', $board);
	$smarty->assign('boards', $boards);
	$smarty->assign('thread', array($thread));
	$smarty->assign('is_moderatable', $is_moderatable);
	$smarty->assign('is_admin', is_admin());
	$smarty->assign('password', $password);
	$smarty->assign('upload_types', $upload_types);
	$smarty->assign('goto', $_SESSION['goto']);
	$smarty->assign('macrochan_tags', $macrochan_tags);
	$smarty->assign('ib_name', Config::IB_NAME);
	$smarty->assign('enable_macro', Config::ENABLE_MACRO);
	$smarty->assign('enable_youtube', Config::ENABLE_YOUTUBE);
	$smarty->assign('ATTACHMENT_TYPE_FILE', Config::ATTACHMENT_TYPE_FILE);
	$smarty->assign('ATTACHMENT_TYPE_LINK', Config::ATTACHMENT_TYPE_LINK);
	$smarty->assign('ATTACHMENT_TYPE_VIDEO', Config::ATTACHMENT_TYPE_VIDEO);
	$smarty->assign('ATTACHMENT_TYPE_IMAGE', Config::ATTACHMENT_TYPE_IMAGE);
	//event_daynight($smarty);	// EVENT HERE! (not default kotoba function)
	$view_html = $smarty->fetch('threads_header.tpl');
	$view_thread_html = '';
	$view_posts_html = '';
	$original_post = null;				// Оригинальное сообщение с допольнительными полями.
	$original_attachments = array();	// Массив вложений оригинального сообщения.
	$simple_attachments = array();		// Массив вложений сообщения.
	foreach($posts as $p)
	{
		// Имя отправителя по умолчанию.
		if(!$board['force_anonymous'] && $board['default_name']
			&& !$p['name'])
		{
			$p['name'] = $board['default_name'];
		}
		// Оригинальное сообщение.
		if($thread['original_post'] == $p['number'])
		{
			$p['with_attachments'] = false;
			foreach($posts_attachments as $pa)
				if($pa['post'] == $p['id'])
					foreach($attachments as $a)
						if($a['attachment_type'] == $pa['attachment_type'])
						{
							switch($a['attachment_type'])
							{
								case Config::ATTACHMENT_TYPE_FILE:
									if($a['id'] == $pa['file'])
									{
										$a['file_link'] = Config::DIR_PATH . "/{$board['name']}/other/{$a['name']}";
										$a['thumbnail_link'] = Config::DIR_PATH . "/img/{$a['thumbnail']}";
										$p['with_attachments'] = true;
										array_push($original_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_IMAGE:
									if($a['id'] == $pa['image'])
									{
										$a['image_link'] = Config::DIR_PATH . "/{$board['name']}/img/{$a['name']}";
										$a['thumbnail_link'] = Config::DIR_PATH . "/{$board['name']}/thumb/{$a['thumbnail']}";
										$p['with_attachments'] = true;
										array_push($original_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_LINK:
									if($a['id'] == $pa['link'])
									{
										$p['with_attachments'] = true;
										array_push($original_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_VIDEO:
									if($a['id'] == $pa['video'])
									{
										$smarty->assign('code', $a['code']);
										$a['video_link'] = $smarty->fetch('youtube.tpl');
										$p['with_attachments'] = true;
										array_push($original_attachments, $a);
									}
									break;
								default:
									throw new CommonException('Not supported.');
									break;
							}
						}
			$p['ip'] = long2ip($p['ip']);
			$smarty->assign('original_post', $p);
			$smarty->assign('original_attachments', $original_attachments);
			$smarty->assign('sticky', $thread['sticky']);
			$view_thread_html = $smarty->fetch('post_original.tpl');
		}
		else
		{
			$p['with_attachments'] = false;
			foreach($posts_attachments as $pa)
				if($pa['post'] == $p['id'])
					foreach($attachments as $a)
						if($a['attachment_type'] == $pa['attachment_type'])
						{
							switch($a['attachment_type'])
							{
								case Config::ATTACHMENT_TYPE_FILE:
									if($a['id'] == $pa['file'])
									{
										$a['file_link'] = Config::DIR_PATH . "/{$board['name']}/other/{$a['name']}";
										$a['thumbnail_link'] = Config::DIR_PATH . "/img/{$a['thumbnail']}";
										$p['with_attachments'] = true;
										array_push($simple_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_IMAGE:
									if($a['id'] == $pa['image'])
									{
										$a['image_link'] = Config::DIR_PATH . "/{$board['name']}/img/{$a['name']}";
										$a['thumbnail_link'] = Config::DIR_PATH . "/{$board['name']}/thumb/{$a['thumbnail']}";
										$p['with_attachments'] = true;
										array_push($simple_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_LINK:
									if($a['id'] == $pa['link'])
									{
										$p['with_attachments'] = true;
										array_push($simple_attachments, $a);
									}
									break;
								case Config::ATTACHMENT_TYPE_VIDEO:
									if($a['id'] == $pa['video'])
									{
										$smarty->assign('code', $a['code']);
										$a['video_link'] = $smarty->fetch('youtube.tpl');
										$p['with_attachments'] = true;
										array_push($simple_attachments, $a);
									}
									break;
								default:
									throw new CommonException('Not supported.');
									break;
							}
						}
			$p['ip'] = long2ip($p['ip']);
			$smarty->assign('simple_post', $p);
			$smarty->assign('simple_attachments', $simple_attachments);
			$view_posts_html .= $smarty->fetch('post_simple.tpl');
			$simple_attachments = array();
		}
	}
	$view_html .= $view_thread_html . $view_posts_html;
	$smarty->assign('hidden_threads', $hidden_threads);
	$view_html .= $smarty->fetch('threads_footer.tpl');
	DataExchange::releaseResources();
	echo $view_html;
	exit;
}
catch(Exception $e)
{
	$smarty->assign('msg', $e->__toString());
	DataExchange::releaseResources();
	die($smarty->fetch('error.tpl'));
}
?>
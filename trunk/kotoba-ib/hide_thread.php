<?php
/* ***********************************
 * Этот файл является частью Kotoba. *
 * Файл license.txt содержит условия *
 * распространения Kotoba.           *
 *************************************/
/* *******************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************/

/*
 * Скрипт скрытия нитей. Скрипт принимает один параметр, который передаётся
 * с помощью POST или GET запроса:
 * thread - Идентификатор нити, которую нужно скрыть.
 */

require 'config.php';
require_once Config::ABS_PATH . '/lib/errors.php';
require Config::ABS_PATH . '/locale/' . Config::LANGUAGE . '/errors.php';
require_once Config::ABS_PATH . '/lib/logging.php';
require Config::ABS_PATH . '/locale/' . Config::LANGUAGE . '/logging.php';
require_once Config::ABS_PATH . '/lib/db.php';
require_once Config::ABS_PATH . '/lib/misc.php';

try {
    // Инициализация.
    kotoba_session_start();
    locale_setup();
    $smarty = new SmartyKotobaSetup($_SESSION['language'], $_SESSION['stylesheet']);

    // Проверка, не заблокирован ли клиент.
    if (($ip = ip2long($_SERVER['REMOTE_ADDR'])) === false) {
        throw new CommonException(CommonException::$messages['REMOTE_ADDR']);
    }
    if (($ban = bans_check($ip)) !== false) {
        $smarty->assign('ip', $_SERVER['REMOTE_ADDR']);
        $smarty->assign('reason', $ban['reason']);
        session_destroy();
        DataExchange::releaseResources();
        die($smarty->fetch('banned.tpl'));
    }

    // Гости не могут скрывать нити.
    if (is_guest()) {
        throw new PermissionException(PermissionException::$messages['GUEST']);
    }

    // Проверка входных параметров и получение данных о нити.
    $REQUEST = "_{$_SERVER['REQUEST_METHOD']}";
    $REQUEST = $$REQUEST;
    if (isset($REQUEST['thread'])) {
        $thread = threads_get_by_id(threads_check_id($REQUEST['thread']));
    } else {
        header('Location: http://z0r.de/?id=114');
        DataExchange::releaseResources();
        exit(1);
    }

    // Скрытие нити.
    hidden_threads_add($thread['id'], $_SESSION['user']);
    header('Location: ' . Config::DIR_PATH . "/{$thread['board']['name']}/");

    // Освобождение ресурсов и очистка.
    DataExchange::releaseResources();

    exit(0);
} catch(Exception $e) {
    $smarty->assign('msg', $e->__toString());
    DataExchange::releaseResources();
    die($smarty->fetch('error.tpl'));
}
?>

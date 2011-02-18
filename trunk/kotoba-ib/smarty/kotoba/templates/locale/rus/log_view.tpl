{* Smarty *}
{*********************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************}
{*
Code of log view page.

Variables:
    $DIR_PATH - path from server document root to index.php directory (see config.default).
    $STYLESHEET - stylesheet (see config.default).
    $show_control - show link to manage page.
    $boards - boards.
    $log - log.
*}
{include file='header.tpl' DIR_PATH=$DIR_PATH STYLESHEET=$STYLESHEET page_title="Просмотр лога"}

{include file='adminbar.tpl' DIR_PATH=$DIR_PATH show_control=$show_control}

{include file='navbar.tpl' DIR_PATH=$DIR_PATH boards=$boards}

<div class="logo">Просмотр лога</div>

<hr>
<table cellspacing="2" cellpadding="1" border="1" width="100%">
<tr><th>Дата</th><th>id пользователя</th><th>Группы пользователя</th><th>IP-адрес</th><th>Сообщение</th></tr>
{section name=i loop=$log}
    <tr><td>{$log[i][0]}</td><td>{$log[i][1]}</td><td>{$log[i][2]}</td><td>{$log[i][3]}</td><td>{$log[i][4]}</td></tr>
{/section}
</table>
{include file='footer.tpl'}
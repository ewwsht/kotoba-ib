{* Smarty *}
{*********************************
 * This file is part of Kotoba.  *
 * See license.txt for more info.*
 *********************************}
{*
Navigation bar.

Variables:
    $DIR_PATH - path from server document root to index.php directory (see config.default).
    $INVISIBLE_BOARDS - list of board names what will not shown in navigation bar. (see config.default).
    $boards - boards.
*}
<div class="navbar">
{assign var="category" value=""}
{assign var="count" value=0}
{section name=i loop=$boards}
{if !isset($INVISIBLE_BOARDS) || !in_array($boards[i].name, $INVISIBLE_BOARDS)}
{if $category != $boards[i].category_name}{if $smarty.section.i.index > 0}]
{/if}
[{assign var="category" value=$boards[i].category_name} {$category}: {else} / {/if}
<a href="{$DIR_PATH}/{$boards[i].name}/">{$boards[i].name}</a>{math equation="c+1" c=$count assign=count}{/if}{/section}
{if $smarty.section.i.index > 0} ]
{/if}
[<a href="{$DIR_PATH}/">Главная</a>]
</div>

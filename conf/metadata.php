<?php
/**
 * Options for the davcal plugin
 *
 * @author Andreas Boehler <dev@aboehler.at>
 */


$meta['hide_settings']          = array('onoff');
$meta['disable_sync']           = array('onoff');
$meta['disable_ics']            = array('onoff');
$meta['monday']                 = array('onoff');
$meta['timezone']               = array('string');
$meta['timeformat']             = array('multichoice', '_choices' => array('lang', '24h', '12h'));
$meta['workweek']               = array('onoff');
$meta['weeknumbers']            = array('onoff');
$meta['default_client_id']      = array('string');

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
SuperAdmin::logout();
header('Location: login.php');

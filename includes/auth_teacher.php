<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION["user"])) { header("Location: /uiu_brainnext/"); exit; }
if (($_SESSION["user"]["role"] ?? "") !== "teacher") { header("Location: /uiu_brainnext/"); exit; }


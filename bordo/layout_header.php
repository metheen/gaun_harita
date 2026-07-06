<?php
declare(strict_types=1);

/**
 * Ortak panel arayüzü (üst kısım). Korumalı sayfalar auth.php'den sonra dahil eder.
 * Beklenen değişken: $pageTitle (isteğe bağlı).
 */

require_once __DIR__ . '/../auth_roles.php';

$pageTitle = $pageTitle ?? 'Yönetim Paneli';
$current   = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — GAÜN Kampüs Paneli</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">

    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <span class="navbar-text">Hoş geldiniz, <strong><?= e($_SESSION['username'] ?? '') ?></strong></span>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a href="logout.php" class="btn btn-danger btn-sm">Çıkış Yap</a>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light">GAÜN Kampüs Panel</span>
        </a>
        <div class="sidebar">
            <nav>
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link <?= $current === 'dashboard.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="locations.php" class="nav-link <?= $current === 'locations.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-map-marker-alt"></i>
                            <p>Konum Yönetimi</p>
                        </a>
                    </li>
                    <?php if (auth_can('manage_settings')): ?>
                    <li class="nav-item">
                        <a href="security.php" class="nav-link <?= $current === 'security.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>Güvenlik</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link <?= $current === 'settings.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-cogs"></i>
                            <p>Ayarlar</p>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (auth_can('manage_users')): ?>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link <?= $current === 'users.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-users-cog"></i>
                            <p>Kullanıcılar</p>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a href="/" target="_blank" class="nav-link">
                            <i class="nav-icon fas fa-external-link-alt"></i>
                            <p>Haritayı Görüntüle</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="content-wrapper p-3">
        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>

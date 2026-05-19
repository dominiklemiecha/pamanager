<?php
/**
 * Stile condiviso .cd-tabs (preso dalla dashboard consulente).
 * Includere una sola volta per pagina con: include __DIR__ . '/../includes/_cd-tabs.inc.php';
 */
if (!defined('CD_TABS_CSS_LOADED')) {
    define('CD_TABS_CSS_LOADED', true);
    ?>
    <style>
    .cd-tabs {
        display: flex; gap: 2px; background: #f1f5f9; border-radius: 10px;
        padding: 4px; flex-wrap: wrap;
    }
    .cd-tab {
        padding: 7px 14px; border-radius: 8px;
        font-size: 12px; font-weight: 600;
        color: #6e7191; text-decoration: none;
        white-space: nowrap; transition: all .12s ease;
        border: none; background: transparent; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .cd-tab:hover { color: #0b3aa4; text-decoration: none; }
    .cd-tab.active { background: white; color: #0b3aa4; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }
    .cd-tab .cd-tab-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 18px; height: 18px; padding: 0 5px;
        background: #fee2e2; color: #b91c1c;
        border-radius: 999px; font-size: 10px; font-weight: 700;
    }
    .cd-tab.active .cd-tab-badge { background: #0b3aa4; color: white; }
    </style>
    <?php
}

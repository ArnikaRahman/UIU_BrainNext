<?php
require_once __DIR__ . "/functions.php";

if (!defined("BASE_URL")) define("BASE_URL", "/uiu_brainnext");

/**
 * Start UI Layout
 */
function ui_start(string $title, string $subtitle = ""): void {
  if (session_status() === PHP_SESSION_NONE) session_start();

  $user = $_SESSION["user"] ?? null;
  $name = $user["full_name"] ?? $user["username"] ?? "";
  $role = $user["role"] ?? "";

  echo '<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . e($title) . '</title>
  <link rel="stylesheet" href="' . BASE_URL . '/assets/css/style.css?v=2">
  <style>
    /* ✅ Global fix: avoid bottom "second layer" band */
    body { padding: 22px 0; }
    .container { margin: 0 auto; }

    /* =========================
       PROFILE DROPDOWN (NAME)
       ========================= */
    .profile-wrap{ position:relative; display:flex; align-items:center; gap:10px; }
    .profile-btn{
      cursor:pointer;
      user-select:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid rgba(90,130,190,.28);
      background: rgba(10, 18, 30, .35);
      font-weight:900;
    }
    .profile-caret{ opacity:.75; font-size:12px; }
    .profile-menu{
      position:absolute;
      right:0;
      top:calc(100% + 10px);
      min-width:240px;
      border-radius:16px;
      border:1px solid rgba(255,255,255,.12);
      background: rgba(10,15,25,.95);
      box-shadow:0 20px 60px rgba(0,0,0,.45);
      padding:8px;
      display:none;
      z-index:9999;
    }
    .profile-menu a,
    .profile-menu button{
      width:100%;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:10px 12px;
      border-radius:12px;
      border:0;
      background:transparent;
      color:inherit;
      text-decoration:none;
      cursor:pointer;
      font-weight:800;
      opacity:.92;
      text-align:left;
    }
    .profile-menu a:hover,
    .profile-menu button:hover{
      background: rgba(255,255,255,.06);
      opacity:1;
    }
    .profile-menu .danger{ color:#ff9a9a; }
    .menu-sep{ height:1px; margin:6px 4px; background:rgba(255,255,255,.10); }

    /* ✅ Modal base (so My Course works even if CSS missing elsewhere) */
    .modal-backdrop{
      position:fixed; inset:0;
      background:rgba(0,0,0,.55);
      display:none;
      align-items:center; justify-content:center;
      z-index:99999;
      padding:16px;
    }
    .modal{
      width:min(980px, 96vw);
      background:rgba(12,18,26,.96);
      border:1px solid rgba(255,255,255,.10);
      border-radius:18px;
      box-shadow:0 20px 60px rgba(0,0,0,.55);
      overflow:hidden;
    }
    .modal-head{
      display:flex; align-items:center; justify-content:space-between;
      padding:14px 16px;
      border-bottom:1px solid rgba(255,255,255,.08);
    }
    .modal-title{ font-size:18px; font-weight:900; }
    .modal-sub{ opacity:.75; font-size:13px; margin-top:2px; }
    .modal-body{ padding:14px 16px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="nav">
      <div class="left">
        <a href="' . e(BASE_URL . "/") . '" style="text-decoration:none;color:inherit;">
          <div class="badge">UIU BrainNext</div>
        </a>
        <div class="muted">' . e($subtitle ?: ($role ? ucfirst($role) . " Panel" : "")) . '</div>
      </div>';

  if ($user) {

    // ✅ Student: show NAME dropdown only (no extra logout badge)
    if ($role === "student" || $role === "teacher") {

      echo '<div class="left">
        <div class="profile-wrap" id="profileWrap">
          <div class="profile-btn" id="profileBtn" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <span>' . e($name) . '</span>
            <span class="profile-caret">▼</span>
          </div>

          <div class="profile-menu" id="profileMenu" role="menu" aria-label="Profile menu">
            <!-- ui_top_actions() will inject items here -->
            <div id="uiMenuInjected"></div>';

      // ✅ Student-only extra item (kept from previous UI)
      if ($role === "student") {
        echo '<div class="menu-sep"></div>
              <button type="button" onclick="window.dispatchEvent(new CustomEvent(\'ui:openCourses\'));"><span>My Course</span><span>→</span></button>';
      }

      echo '<div class="menu-sep"></div>

            <a class="danger" href="' . BASE_URL . '/logout.php" role="menuitem">
              <span>Logout</span><span>⎋</span>
            </a>
          </div>
        </div>
      </div>';

      // ✅ Dropdown JS (student + teacher)
      echo '<script>
        (function(){
          const wrap = document.getElementById("profileWrap");
          const btn  = document.getElementById("profileBtn");
          const menu = document.getElementById("profileMenu");

          function openMenu(){
            menu.style.display = "block";
            btn.setAttribute("aria-expanded","true");
          }
          function closeMenu(){
            menu.style.display = "none";
            btn.setAttribute("aria-expanded","false");
          }

          btn.addEventListener("click", function(){
            const isOpen = (menu.style.display === "block");
            isOpen ? closeMenu() : openMenu();
          });

          btn.addEventListener("keydown", function(e){
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); btn.click(); }
          });

          document.addEventListener("click", function(e){
            if (!wrap.contains(e.target)) closeMenu();
          });

          document.addEventListener("keydown", function(e){
            if (e.key === "Escape") closeMenu();
          });

          // allow other scripts to close the menu
          window.uiCloseProfileMenu = closeMenu;

          // helper: inject menu items from ui_top_actions
          window.uiInjectMenuItems = function(items){
            const box = document.getElementById("uiMenuInjected");
            if (!box) return;
            box.innerHTML = "";

            (items || []).forEach(function(it){
              const a = document.createElement("a");
              a.href = it.url;
              a.innerHTML = "<span>"+it.label+"</span><span>→</span>";
              a.addEventListener("click", function(){ closeMenu(); });
              box.appendChild(a);
            });
          };
        })();
      </script>';

    } else {
      // ✅ Other roles keep old layout (name + logout)
      echo '<div class="left">
        <span class="muted">' . e($name) . '</span>
        <a class="badge" href="' . BASE_URL . '/logout.php">Logout</a>
      </div>';
    }
  }

  echo '</div>'; // nav end
}

/**
 * End UI Layout
 */
function ui_end(): void {
  if (session_status() === PHP_SESSION_NONE) session_start();

  $role = $_SESSION["user"]["role"] ?? "";

  // ✅ GLOBAL My Course modal for students (works on every page)
  if ($role === "student") {
    $en = $_SESSION["student_enrollments"] ?? [];
    echo '
    <div id="coursesModalBackdrop" class="modal-backdrop" aria-hidden="true">
      <div class="modal" role="dialog" aria-modal="true" aria-label="My Course">
        <div class="modal-head">
          <div>
            <div class="modal-title">My Course (Enrollments)</div>
            <div class="modal-sub">Only sections assigned by admin.</div>
          </div>
          <button class="btn btn-ghost" type="button" id="coursesModalClose">✕</button>
        </div>
        <div class="modal-body">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Course</th>
                  <th>Section</th>
                  <th>Trimester</th>
                  <th>Teacher</th>
                </tr>
              </thead>
              <tbody>';

    if (!$en) {
      echo "<tr><td colspan='4'><span class='pill pill-wa'>No enrollments found.</span></td></tr>";
    } else {
      foreach ($en as $r) {
        $course = e(($r["course_code"] ?? "") . " - " . ($r["course_title"] ?? ""));
        $sec    = e($r["section_label"] ?? "");
        $tri    = e(($r["trimester_name"] ?? ($r["trimester"] ?? "")) . " / " . ($r["year"] ?? ""));
        $tname  = e($r["teacher_name"] ?? $r["teacher_username"] ?? "-");

        echo "<tr>
                <td><span class='pill pill-block'>{$course}</span></td>
                <td><span class='pill pill-block'>{$sec}</span></td>
                <td><span class='pill pill-block'>{$tri}</span></td>
                <td><span class='pill pill-block'>{$tname}</span></td>
              </tr>";
      }
    }

    echo '
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const bd = document.getElementById("coursesModalBackdrop");
        const closeBtn = document.getElementById("coursesModalClose");

        function openModal(){
          if (!bd) return;
          bd.style.display = "flex";
          bd.setAttribute("aria-hidden","false");
          document.body.style.overflow = "hidden";
          if (typeof window.uiCloseProfileMenu === "function") window.uiCloseProfileMenu();
        }

        function closeModal(){
          if (!bd) return;
          bd.style.display = "none";
          bd.setAttribute("aria-hidden","true");
          document.body.style.overflow = "";
        }

        if (closeBtn) closeBtn.addEventListener("click", closeModal);
        if (bd) bd.addEventListener("click", function(e){ if (e.target === bd) closeModal(); });

        document.addEventListener("keydown", function(e){
          if (e.key === "Escape") closeModal();
        });

        // ✅ This is the missing piece: works on EVERY page now
        window.addEventListener("ui:openCourses", openModal);
      })();
    </script>';
  }

  echo '</div></body></html>';
}

/**
 * Top action buttons row
 * For students: put buttons inside NAME dropdown menu.
 * For others: keep normal row buttons.
 */
function ui_top_actions(array $items): void {
  if (session_status() === PHP_SESSION_NONE) session_start();

  $role = $_SESSION["user"]["role"] ?? "";
  $uri = $_SERVER["REQUEST_URI"] ?? "";
  $uriPath = parse_url($uri, PHP_URL_PATH) ?: $uri;

  // Convert current path to "relative to BASE_URL"
  $current = $uriPath;
  if (strpos($current, BASE_URL) === 0) {
    $current = substr($current, strlen(BASE_URL));
    if ($current === "") $current = "/";
  }

  // ✅ STUDENT: inject into dropdown menu (do not show big row)
  if ($role === "student" || $role === "teacher") {
    $menuItems = [];
    foreach ($items as $it) {
      $label = $it[0] ?? "";
      $href  = $it[1] ?? "#";

      $isExternal = preg_match('~^https?://~i', $href) === 1;

      $target = $href;
      if (!$isExternal) {
        if ($target === "" || $target[0] !== "/") $target = "/" . $target;
        if (strpos($target, BASE_URL) === 0) {
          $target = substr($target, strlen(BASE_URL));
          if ($target === "") $target = "/";
        }
        if ($target === $current) continue; // hide current page
      }

      $finalUrl = $isExternal ? $href : (BASE_URL . $href);
      $menuItems[] = ["label" => $label, "url" => $finalUrl];
    }

    // Output JS to inject items into profile menu
    echo '<script>
      (function(){
        var items = ' . json_encode($menuItems, JSON_UNESCAPED_SLASHES) . ';
        if (typeof window.uiInjectMenuItems === "function") {
          window.uiInjectMenuItems(items);
        } else {
          // fallback if script loads later
          window.__uiMenuPending = items;
          window.addEventListener("load", function(){
            if (typeof window.uiInjectMenuItems === "function") window.uiInjectMenuItems(window.__uiMenuPending || []);
          });
        }
      })();
    </script>';

    return;
  }

  // ✅ NON-STUDENT: keep normal top button row
  echo '<div class="top-actions">';

  foreach ($items as $it) {
    $label = $it[0] ?? "";
    $href  = $it[1] ?? "#";

    $isExternal = preg_match('~^https?://~i', $href) === 1;

    $target = $href;
    if ($isExternal) {
      $target = "";
    } else {
      if ($target === "" || $target[0] !== "/") $target = "/" . $target;
      if (strpos($target, BASE_URL) === 0) {
        $target = substr($target, strlen(BASE_URL));
        if ($target === "") $target = "/";
      }
    }

    if (!$isExternal && $target !== "" && $target === $current) continue;

    $finalUrl = $isExternal ? $href : (BASE_URL . $href);

    echo '<div class="top-box">
            <a class="top-btn" href="' . e($finalUrl) . '">' . e($label) . '</a>
          </div>';
  }

  echo '</div>';
}









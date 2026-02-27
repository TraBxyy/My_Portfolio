<?php
session_start();

// ==========================================
// 1. ตั้งค่าฐานข้อมูล (Database Configuration)
// ==========================================
$db_host = 'localhost';
$db_user = 'root';      // ใส่ Username ฐานข้อมูลของคุณ
$db_pass = '';          // ใส่ Password ฐานข้อมูลของคุณ
$db_name = 'portfolio_db'; // ชื่อฐานข้อมูล (ต้องสร้าง Database ชื่อนี้ไว้ก่อน)

// ตั้งค่ารหัสผ่านสำหรับเข้า Admin
$admin_password = 'Bally140147!'; 

// ==========================================
// 2. เชื่อมต่อและสร้างตารางอัตโนมัติ
// ==========================================
try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // สร้าง Database หากยังไม่มี
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // สร้างตารางผลงาน (เพิ่มคอลัมน์ sort_order สำหรับจัดเรียง)
    $tableQuery = "CREATE TABLE IF NOT EXISTS portfolio_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title_th VARCHAR(255) NOT NULL,
        title_en VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        media_url TEXT NOT NULL,
        media_type VARCHAR(50) NOT NULL DEFAULT 'image',
        aspect_ratio ENUM('16:9', '9:16') NOT NULL DEFAULT '16:9',
        is_featured TINYINT(1) DEFAULT 0,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($tableQuery);

    // สร้างตารางตั้งค่าโปรไฟล์และช่องทางการติดต่อ
    $settingsQuery = "CREATE TABLE IF NOT EXISTS portfolio_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )";
    $pdo->exec($settingsQuery);

    // อัปเดตตารางเก่าให้รองรับการจัดเรียง
    try {
        $pdo->query("SELECT sort_order FROM portfolio_items LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE portfolio_items ADD COLUMN sort_order INT DEFAULT 0");
    }
    
    // อัปเดตตารางเก่าให้รองรับ media_type รูปแบบใหม่ที่เป็น VARCHAR แทน ENUM
    try {
        $pdo->exec("ALTER TABLE portfolio_items MODIFY COLUMN media_type VARCHAR(50) NOT NULL DEFAULT 'image'");
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage() . "<br>กรุณาตรวจสอบการตั้งค่า Database ที่บรรทัด 7-10");
}

// ==========================================
// 3. จัดการระบบภาษา (Language System)
// ==========================================
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'] === 'en' ? 'en' : 'th';
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}
$lang = $_SESSION['lang'] ?? 'th';

$t = [
    'th' => [
        'name' => 'ธีรติ บรรณารักษ์',
        'nickname' => 'บอล',
        'role' => 'Creative & Creator',
        'hero_desc' => 'ขออนุญาตบุคคลในภาพหรือวิดีโอ ผมไม่ได้มีเจตนาที่จะทำให้เสียหาย ผมขอพื้นที่ลงผลงานที่ผมได้เคยทำมา หากท่านไม่อนุญาตสามารถติดต่อผมมาได้ครับ 🙏🏻',
        'featured' => 'ผลงานเด่น',
        'all_works' => 'ผลงานทั้งหมด',
        'category_all' => 'ทั้งหมด',
        'contact_me' => 'ช่องทางการติดต่อ',
        'admin_login' => 'เข้าสู่ระบบผู้ดูแล',
        'switch_lang' => 'EN',
        'switch_lang_code' => 'en',
        'footer' => 'สงวนลิขสิทธิ์ © ' . date('Y') . ' ธีรติ บรรณารักษ์'
    ],
    'en' => [
        'name' => 'Teerati Bannarak',
        'nickname' => 'Ball',
        'role' => 'Creative & Creator',
        'hero_desc' => 'I have no intention of causing harm to the individuals in the photos or videos. I am simply requesting permission to showcase my past work. If you do not grant permission, please feel free to contact me. 🙏🏻',
        'featured' => 'Featured Works',
        'all_works' => 'All Projects',
        'category_all' => 'All',
        'contact_me' => 'Contact Me',
        'admin_login' => 'Admin Login',
        'switch_lang' => 'TH',
        'switch_lang_code' => 'th',
        'footer' => 'Copyright © ' . date('Y') . ' Teerati Bannarak'
    ]
];
$text = $t[$lang];
$categories = ['YouTube', 'Tiktok', 'Banner', 'Sketchup', 'Artwork', 'Certificate'];

// ==========================================
// 4. จัดการระบบ Backend (Admin Logic)
// ==========================================
$page = $_GET['page'] ?? 'home';

// Login Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: ?page=admin");
        exit;
    } else {
        $login_error = "รหัสผ่านไม่ถูกต้อง!";
    }
}

// Logout Logic
if ($page === 'logout') {
    session_destroy();
    header("Location: ?page=home");
    exit;
}

// Check Admin Auth
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// ตรวจสอบและสร้างโฟลเดอร์ uploads หากยังไม่มี
$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// CRUD Operations & Upload File & Sort Order
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // จัดการการเรียงลำดับใหม่ (Drag & Drop AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'update_order') {
        $order = json_decode($_POST['order'], true);
        if (is_array($order)) {
            foreach ($order as $index => $item_id) {
                $stmt = $pdo->prepare("UPDATE portfolio_items SET sort_order = ? WHERE id = ?");
                $stmt->execute([$index, $item_id]);
            }
        }
        exit('Success');
    }
    
    // อัปเดตข้อมูลโปรไฟล์และการติดต่อ
    if (isset($_POST['update_profile'])) {
        $fb_url = $_POST['facebook_url'] ?? '';
        $ig_url = $_POST['instagram_url'] ?? '';
        
        $pdo->prepare("REPLACE INTO portfolio_settings (setting_key, setting_value) VALUES ('facebook_url', ?)")->execute([$fb_url]);
        $pdo->prepare("REPLACE INTO portfolio_settings (setting_key, setting_value) VALUES ('instagram_url', ?)")->execute([$ig_url]);

        // อัปโหลดรูปโปรไฟล์
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file_info = pathinfo($_FILES['profile_image']['name']);
            $extension = strtolower($file_info['extension'] ?? '');
            $target_path = $upload_dir . 'profile_' . time() . '.' . $extension;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
                // ลบรูปเดิมถ้ามี
                $stmt = $pdo->query("SELECT setting_value FROM portfolio_settings WHERE setting_key = 'profile_image'");
                $old_img = $stmt->fetchColumn();
                if ($old_img && file_exists($old_img)) unlink($old_img);

                $pdo->prepare("REPLACE INTO portfolio_settings (setting_key, setting_value) VALUES ('profile_image', ?)")->execute([$target_path]);
            }
        }
        header("Location: ?page=admin");
        exit;
    }

    if (isset($_POST['add_item']) || isset($_POST['edit_item'])) {
        $id = $_POST['item_id'] ?? null;
        $title_th = $_POST['title_th'];
        $title_en = $_POST['title_en'];
        $category = $_POST['category'];
        $media_type = $_POST['media_type'];
        $aspect_ratio = $_POST['aspect_ratio'];
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        
        $media_url = $_POST['existing_media_url'] ?? '';

        // จัดการอัปโหลดไฟล์ หรือ ลิงก์
        if ($media_type === 'video_embed') {
            $media_url = $_POST['media_url_input'] ?? '';
            
            // --- ระบบแปลงลิงก์ YouTube & TikTok อัตโนมัติ ---
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/|youtube\.com\/shorts\/)([^"&?\/\s]{11})/i', $media_url, $matches)) {
                $media_url = 'https://www.youtube.com/embed/' . $matches[1];
            } 
            elseif (preg_match('/tiktok\.com\/@[^\/]+\/video\/([0-9]+)/i', $media_url, $matches)) {
                $media_url = 'https://www.tiktok.com/embed/v2/' . $matches[1];
            }
            
        } else {
            if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                $file_info = pathinfo($_FILES['media_file']['name']);
                $extension = strtolower($file_info['extension'] ?? '');
                
                // สร้างชื่อไฟล์ใหม่แบบสุ่ม
                $new_filename = uniqid('media_') . '.' . $extension;
                $target_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['media_file']['tmp_name'], $target_path)) {
                    if (!empty($media_url) && file_exists($media_url)) {
                        unlink($media_url);
                    }
                    $media_url = $target_path;
                }
            } elseif (isset($_FILES['media_file']) && $_FILES['media_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                echo "<script>alert('เกิดข้อผิดพลาดในการอัปโหลดไฟล์ รหัส: " . $_FILES['media_file']['error'] . " (ขนาดไฟล์อาจใหญ่เกินไป)');</script>";
            }
        }

        // เช็คจำนวน Featured 9:16 ไม่ให้เกิน 2
        if ($is_featured == 1 && $aspect_ratio === '9:16') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM portfolio_items WHERE is_featured = 1 AND id != " . ($id ? $id : 0));
            if ($stmt->fetchColumn() >= 2) {
                 $is_featured = 0;
            }
        }

        if (isset($_POST['add_item'])) {
            $stmt = $pdo->prepare("INSERT INTO portfolio_items (title_th, title_en, category, media_url, media_type, aspect_ratio, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title_th, $title_en, $category, $media_url, $media_type, $aspect_ratio, $is_featured]);
        } else {
            $stmt = $pdo->prepare("UPDATE portfolio_items SET title_th=?, title_en=?, category=?, media_url=?, media_type=?, aspect_ratio=?, is_featured=? WHERE id=?");
            $stmt->execute([$title_th, $title_en, $category, $media_url, $media_type, $aspect_ratio, $is_featured, $id]);
        }
        header("Location: ?page=admin");
        exit;
    }

    if (isset($_POST['delete_item'])) {
        $stmt = $pdo->prepare("SELECT media_url FROM portfolio_items WHERE id=?");
        $stmt->execute([$_POST['item_id']]);
        $item = $stmt->fetch();
        if ($item && !empty($item['media_url']) && file_exists($item['media_url'])) {
            unlink($item['media_url']);
        }
        $stmt = $pdo->prepare("DELETE FROM portfolio_items WHERE id=?");
        $stmt->execute([$_POST['item_id']]);
        header("Location: ?page=admin");
        exit;
    }
}

// Fetch Data for Frontend
$stmt_featured = $pdo->query("SELECT * FROM portfolio_items WHERE is_featured = 1 AND aspect_ratio = '9:16' ORDER BY sort_order ASC, created_at DESC LIMIT 2");
$featured_items = $stmt_featured->fetchAll(PDO::FETCH_ASSOC);

$stmt_all = $pdo->query("SELECT * FROM portfolio_items ORDER BY sort_order ASC, created_at DESC");
$all_items = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

// Fetch Settings
$stmt_settings = $pdo->query("SELECT * FROM portfolio_settings");
$settings_data = $stmt_settings->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach($settings_data as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$profile_img = !empty($settings['profile_image']) ? $settings['profile_image'] : 'https://ui-avatars.com/api/?name=Teerati&background=0D8ABC&color=fff&size=256';
$fb_url = $settings['facebook_url'] ?? '';
$ig_url = $settings['instagram_url'] ?? '';

// ==========================================
// 5. ส่วนแสดงผล HTML (Frontend & Backend)
// ==========================================
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $text['name']; ?> | Portfolio</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js สำหรับ UI Interactions -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- SortableJS สำหรับระบบ Drag & Drop เรียงคิว -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts: Prompt & Kanit -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- AOS Animation CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Prompt', sans-serif; background-color: #0f172a; color: #f8fafc; overflow-x: hidden; }
        .font-kanit { font-family: 'Kanit', sans-serif; }
        
        /* Smooth Scroll & Transitions */
        html { scroll-behavior: smooth; }
        .card-hover { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-8px); box-shadow: 0 20px 40px -10px rgba(56, 189, 248, 0.3); border-color: #38bdf8; }
        
        /* Aspect Ratios */
        .aspect-16-9 { aspect-ratio: 16 / 9; }
        .aspect-9-16 { aspect-ratio: 9 / 16; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        
        /* Hide scrollbar for tabs */
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Drag handle UI */
        .sortable-ghost { opacity: 0.4; background-color: #334155; }
        .cursor-grab { cursor: -webkit-grab; cursor: grab; }
        .cursor-grab:active { cursor: -webkit-grabbing; cursor: grabbing; }

        /* Custom Gradient for IG */
        .ig-gradient { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); }
        
        /* Animated Background Elements */
        .blob {
            position: absolute; filter: blur(60px); z-index: -1; opacity: 0.4;
            animation: move 10s infinite alternate;
        }
        @keyframes move {
            from { transform: translate(0px, 0px) scale(1); }
            to { transform: translate(30px, -50px) scale(1.1); }
        }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col" x-data="{ showLightbox: false, lightboxType: '', lightboxUrl: '' }" @keydown.escape="showLightbox = false; lightboxUrl = ''">

    <!-- Navbar -->
    <nav class="fixed w-full z-50 top-0 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <a href="?page=home" class="flex items-center gap-3 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-sky-400 to-indigo-500 text-white flex items-center justify-center font-bold text-xl group-hover:rotate-12 transition-transform shadow-lg shadow-sky-500/30">B</div>
                    <span class="font-bold text-xl tracking-wide text-transparent bg-clip-text bg-gradient-to-r from-white to-slate-400 group-hover:to-white transition-colors"><?php echo $lang === 'th' ? 'ธีรติ.' : 'Teerati.'; ?></span>
                </a>
                <div class="flex items-center gap-4">
                    <?php if ($is_admin): ?>
                        <a href="?page=admin" class="text-sm text-sky-400 hover:text-sky-300 font-medium">
                            <i class="fas fa-cog mr-1"></i> Admin Panel
                        </a>
                        <a href="?page=logout" class="text-sm text-rose-400 hover:text-rose-300 font-medium ml-2">Logout</a>
                    <?php else: ?>
                        <a href="#contact" class="hidden md:inline-flex text-sm font-medium text-slate-300 hover:text-white transition-colors">
                            <?php echo $text['contact_me']; ?>
                        </a>
                        <a href="?lang=<?php echo $text['switch_lang_code']; ?>" class="text-sm font-medium px-4 py-1.5 rounded-full border border-slate-700 hover:border-sky-500 hover:text-sky-400 transition-all ml-2">
                            <?php echo $text['switch_lang']; ?>
                        </a>
                        <a href="?page=admin" class="text-sm text-slate-600 hover:text-slate-400 transition-colors ml-2" title="<?php echo $text['admin_login']; ?>">
                            <i class="fas fa-lock"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="flex-grow pt-16">
        
        <?php if ($page === 'admin'): ?>
            <?php if (!$is_admin): ?>
                <!-- Login Page -->
                <div class="min-h-[80vh] flex items-center justify-center px-4 relative">
                    <div class="blob bg-sky-600 w-64 h-64 rounded-full top-1/4 left-1/4"></div>
                    <div class="blob bg-indigo-600 w-64 h-64 rounded-full bottom-1/4 right-1/4" style="animation-delay: -5s"></div>
                    
                    <div class="bg-slate-800/80 backdrop-blur-xl p-8 rounded-3xl shadow-2xl w-full max-w-md border border-slate-700 relative z-10" data-aos="zoom-in">
                        <div class="text-center mb-8">
                            <div class="w-20 h-20 bg-gradient-to-br from-sky-400 to-indigo-500 text-white rounded-full flex items-center justify-center mx-auto mb-6 text-3xl shadow-lg shadow-sky-500/40"><i class="fas fa-lock"></i></div>
                            <h2 class="text-2xl font-bold font-kanit"><?php echo $text['admin_login']; ?></h2>
                        </div>
                        <?php if(isset($login_error)): ?>
                            <div class="bg-rose-500/10 border border-rose-500/50 text-rose-400 p-3 rounded-xl text-sm mb-6 text-center animate-pulse"><?php echo $login_error; ?></div>
                        <?php endif; ?>
                        <form method="POST" class="space-y-5">
                            <div>
                                <input type="password" name="password" placeholder="Enter Password..." class="w-full bg-slate-900/50 border border-slate-700 rounded-xl px-5 py-3.5 text-white focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/50 transition-all text-center tracking-widest" required>
                            </div>
                            <button type="submit" name="login" class="w-full bg-gradient-to-r from-sky-500 to-indigo-500 hover:from-sky-400 hover:to-indigo-400 text-white font-bold py-3.5 rounded-xl transition-all shadow-lg shadow-sky-500/30 transform hover:-translate-y-1">Login to Dashboard</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Admin Dashboard -->
                <div class="max-w-7xl mx-auto px-4 py-12" x-data="{ 
                        showModal: false, 
                        editMode: false, 
                        currentItem: {}, 
                        adminCategory: '<?php echo $categories[0]; ?>', 
                        adminType: 'image',
                        mainTab: 'portfolio' // เพิ่มตัวแปรคุมแท็บหลัก (ผลงาน vs โปรไฟล์)
                    }">
                    
                    <!-- Header & Main Tabs -->
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                        <div>
                            <h1 class="text-3xl font-bold font-kanit">Admin Dashboard</h1>
                            <p class="text-sm text-slate-400 mt-1">ยินดีต้อนรับกลับ, จัดการแฟ้มผลงานและโปรไฟล์ของคุณได้ที่นี่</p>
                        </div>
                        
                        <div class="flex bg-slate-800 p-1.5 rounded-xl border border-slate-700 w-full md:w-auto">
                            <button @click="mainTab = 'portfolio'" :class="mainTab === 'portfolio' ? 'bg-sky-500 text-white shadow-md' : 'text-slate-400 hover:text-white'" class="flex-1 md:flex-none px-6 py-2.5 rounded-lg font-medium transition-all flex items-center justify-center gap-2">
                                <i class="fas fa-briefcase"></i> จัดการผลงาน
                            </button>
                            <button @click="mainTab = 'profile'" :class="mainTab === 'profile' ? 'bg-indigo-500 text-white shadow-md' : 'text-slate-400 hover:text-white'" class="flex-1 md:flex-none px-6 py-2.5 rounded-lg font-medium transition-all flex items-center justify-center gap-2">
                                <i class="fas fa-id-card"></i> ตั้งค่าโปรไฟล์ & ติดต่อ
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- แท็บ: จัดการผลงาน (Portfolio Management) -->
                    <!-- ============================================== -->
                    <div x-show="mainTab === 'portfolio'" x-transition.opacity>
                        <div class="flex justify-between items-center mb-6 bg-slate-800/30 p-4 rounded-2xl border border-slate-700/50">
                            <div class="text-sm text-sky-400"><i class="fas fa-lightbulb"></i> ทิปส์: ลากจุด 6 จุดซ้ายมือ เพื่อเรียงลำดับผลงาน</div>
                            <button @click="showModal = true; editMode = false; currentItem = {media_type: adminType === 'image' ? 'image' : 'video_upload', aspect_ratio:'16:9', category: adminCategory, media_url:''}" class="bg-sky-500 hover:bg-sky-400 px-5 py-2.5 rounded-xl font-medium flex items-center gap-2 transition-all shadow-lg shadow-sky-500/20 text-white">
                                <i class="fas fa-plus"></i> เพิ่มผลงานใหม่
                            </button>
                        </div>

                        <!-- 1. แท็บเลือกหัวข้อ (Category) -->
                        <div class="mb-4">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">1. เลือกหมวดหมู่ผลงาน</label>
                            <div class="flex gap-2 overflow-x-auto pb-2 scrollbar-hide">
                                <?php foreach($categories as $cat): ?>
                                    <button @click="adminCategory = '<?php echo $cat; ?>'" 
                                            :class="adminCategory === '<?php echo $cat; ?>' ? 'bg-sky-500 text-white shadow-md' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700 hover:text-white'" 
                                            class="px-5 py-2.5 rounded-full border text-sm font-medium transition-all whitespace-nowrap">
                                        <?php echo $cat; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 2. แท็บเลือกประเภทสื่อ (Image / Video) -->
                        <div class="mb-6">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">2. เลือกประเภทที่ต้องการจัดการ</label>
                            <div class="flex gap-2 bg-slate-800/50 p-1.5 rounded-xl inline-flex border border-slate-700">
                                <button @click="adminType = 'image'" :class="adminType === 'image' ? 'bg-sky-600 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'" class="px-6 py-2.5 rounded-lg font-medium transition-all flex items-center gap-2">
                                    <i class="fas fa-image"></i> แฟ้มรูปภาพ
                                </button>
                                <button @click="adminType = 'video'" :class="adminType === 'video' ? 'bg-rose-500 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'" class="px-6 py-2.5 rounded-lg font-medium transition-all flex items-center gap-2">
                                    <i class="fas fa-video"></i> แฟ้มวิดีโอ
                                </button>
                            </div>
                        </div>

                        <!-- พื้นที่แสดงตาราง -->
                        <?php foreach($categories as $cat): ?>
                            <?php foreach(['image', 'video'] as $type): ?>
                                <?php
                                    $filtered_items = array_filter($all_items, function($item) use ($cat, $type) {
                                        if ($item['category'] !== $cat) return false;
                                        if ($type === 'image') return $item['media_type'] === 'image';
                                        return $item['media_type'] !== 'image'; 
                                    });
                                ?>
                                <div x-show="adminCategory === '<?php echo $cat; ?>' && adminType === '<?php echo $type; ?>'" x-cloak class="bg-slate-800 rounded-2xl overflow-hidden border border-slate-700 shadow-xl">
                                    <div class="bg-slate-900/50 px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                                        <h3 class="font-medium text-slate-300">
                                            กำลังจัดการ: <span class="text-sky-400 font-bold"><?php echo $cat; ?></span> 
                                            <i class="fas fa-chevron-right text-xs mx-2 text-slate-600"></i> 
                                            <span class="<?php echo $type === 'image' ? 'text-sky-300' : 'text-rose-300'; ?>"><?php echo $type === 'image' ? 'รูปภาพ' : 'วิดีโอ'; ?></span>
                                        </h3>
                                        <span class="text-xs font-bold text-slate-400 bg-slate-950 px-3 py-1.5 rounded-full border border-slate-700 shadow-inner"><?php echo count($filtered_items); ?> รายการ</span>
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-sm whitespace-nowrap">
                                            <thead class="bg-slate-900/20 text-slate-400 uppercase text-xs">
                                                <tr>
                                                    <th class="px-4 py-4 w-10 text-center">ย้าย</th>
                                                    <th class="px-6 py-4">Title (TH/EN)</th>
                                                    <th class="px-6 py-4">Type/Ratio</th>
                                                    <th class="px-6 py-4">Featured</th>
                                                    <th class="px-6 py-4 text-right">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="sortable-tbody divide-y divide-slate-700/50">
                                                <?php foreach ($filtered_items as $item): ?>
                                                <tr class="hover:bg-slate-700/50 transition-colors bg-slate-800" data-id="<?php echo $item['id']; ?>">
                                                    <td class="px-4 py-4 text-center cursor-grab drag-handle text-slate-500 hover:text-sky-400 transition-colors">
                                                        <i class="fas fa-grip-vertical text-xl"></i>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <div class="font-medium text-white text-base"><?php echo htmlspecialchars($item['title_th']); ?></div>
                                                        <div class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($item['title_en']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-900 border border-slate-700 text-xs">
                                                            <?php 
                                                                if($item['media_type'] === 'image') echo '<i class="fas fa-image text-sky-400"></i> รูปภาพ';
                                                                else if($item['media_type'] === 'video_upload') echo '<i class="fas fa-video text-rose-400"></i> วิดีโอ (อัปโหลด)';
                                                                else echo '<i class="fab fa-youtube text-red-500"></i> วิดีโอ (ลิงก์)';
                                                            ?> 
                                                            <span class="text-slate-500 mx-1">|</span> 
                                                            <span class="text-slate-300"><?php echo $item['aspect_ratio']; ?></span>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <?php if($item['is_featured']): ?>
                                                            <span class="text-amber-400 bg-amber-400/10 px-2 py-1 rounded border border-amber-400/20 text-xs font-medium"><i class="fas fa-star mr-1"></i> เด่น</span>
                                                        <?php else: ?>
                                                            <span class="text-slate-600">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-right space-x-2">
                                                        <button @click="showModal = true; editMode = true; currentItem = <?php echo htmlspecialchars(json_encode($item)); ?>" class="bg-sky-500/10 text-sky-400 hover:bg-sky-500 hover:text-white px-3 py-1.5 rounded-lg transition-all border border-sky-500/20">
                                                            <i class="fas fa-edit mr-1"></i> แก้ไข
                                                        </button>
                                                        <form method="POST" class="inline-block" onsubmit="return confirm('ยืนยันการลบผลงานนี้อย่างถาวร? (ไฟล์ที่อัปโหลดจะถูกลบออกจาก Server ด้วย)');">
                                                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" name="delete_item" class="bg-rose-500/10 text-rose-400 hover:bg-rose-500 hover:text-white px-3 py-1.5 rounded-lg transition-all border border-rose-500/20">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if(empty($filtered_items)): ?>
                                        <div class="text-center py-20 text-slate-500">
                                            <div class="w-16 h-16 bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-700 shadow-inner">
                                                <i class="fas <?php echo $type === 'image' ? 'fa-image text-sky-500/50' : 'fa-video text-rose-500/50'; ?> text-2xl"></i>
                                            </div>
                                            <p class="font-medium text-slate-400 mb-1">ยังไม่มีผลงานประเภทนี้</p>
                                            <p class="text-xs text-slate-500">กดปุ่ม "เพิ่มผลงานใหม่" ด้านบนเพื่อเริ่มต้น</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- ============================================== -->
                    <!-- แท็บ: ตั้งค่าโปรไฟล์ & ติดต่อ (Profile Settings) -->
                    <!-- ============================================== -->
                    <div x-show="mainTab === 'profile'" x-cloak x-transition.opacity>
                        <form method="POST" enctype="multipart/form-data" class="bg-slate-800 rounded-2xl border border-slate-700 shadow-xl overflow-hidden">
                            <div class="bg-slate-900/50 px-8 py-5 border-b border-slate-700">
                                <h3 class="font-bold text-white text-lg flex items-center gap-2"><i class="fas fa-user-circle text-indigo-400"></i> ข้อมูลโปรไฟล์ & ช่องทางการติดต่อ</h3>
                                <p class="text-sm text-slate-400 mt-1">ข้อมูลส่วนนี้จะถูกนำไปสร้างเป็นการ์ดสวยๆ ในหน้าแรกของเว็บไซต์</p>
                            </div>
                            
                            <div class="p-8 space-y-8">
                                <!-- ส่วนรูปโปรไฟล์ -->
                                <div>
                                    <label class="block text-sm font-bold text-white mb-4">1. รูปภาพโปรไฟล์ (Profile Image)</label>
                                    <div class="flex flex-col sm:flex-row items-center gap-6 bg-slate-900/50 p-6 rounded-2xl border border-slate-700/50">
                                        <div class="w-32 h-32 rounded-full overflow-hidden border-4 border-slate-800 shadow-xl flex-shrink-0 relative group">
                                            <img src="<?php echo htmlspecialchars($profile_img); ?>" class="w-full h-full object-cover">
                                            <div class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                <i class="fas fa-camera text-white text-2xl"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow w-full">
                                            <input type="file" name="profile_image" accept="image/*" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-indigo-500 outline-none file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-500/10 file:text-indigo-400 hover:file:bg-indigo-500/20 transition-all cursor-pointer">
                                            <p class="text-xs text-slate-500 mt-2"><i class="fas fa-info-circle text-indigo-400 mr-1"></i> แนะนำให้ใช้รูปทรงจัตุรัส หรือภาพหน้าตรง เพื่อความสวยงาม</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- ส่วน Social Media -->
                                <div>
                                    <label class="block text-sm font-bold text-white mb-4">2. ลิงก์ช่องทางการติดต่อ (Social Links)</label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Facebook -->
                                        <div class="bg-slate-900/50 p-5 rounded-2xl border border-slate-700/50 hover:border-blue-500/50 transition-colors">
                                            <div class="flex items-center gap-3 mb-3">
                                                <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white text-xl"><i class="fab fa-facebook-f"></i></div>
                                                <h4 class="font-bold text-white">Facebook</h4>
                                            </div>
                                            <input type="url" name="facebook_url" value="<?php echo htmlspecialchars($fb_url); ?>" placeholder="https://www.facebook.com/..." class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-all">
                                            <p class="text-xs text-slate-500 mt-2">ใส่ลิงก์หน้าโปรไฟล์ Facebook ของคุณ</p>
                                        </div>

                                        <!-- Instagram -->
                                        <div class="bg-slate-900/50 p-5 rounded-2xl border border-slate-700/50 hover:border-pink-500/50 transition-colors">
                                            <div class="flex items-center gap-3 mb-3">
                                                <div class="w-10 h-10 rounded-full ig-gradient flex items-center justify-center text-white text-xl"><i class="fab fa-instagram"></i></div>
                                                <h4 class="font-bold text-white">Instagram</h4>
                                            </div>
                                            <input type="url" name="instagram_url" value="<?php echo htmlspecialchars($ig_url); ?>" placeholder="https://www.instagram.com/..." class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-pink-500 focus:ring-1 focus:ring-pink-500 outline-none transition-all">
                                            <p class="text-xs text-slate-500 mt-2">ใส่ลิงก์หน้าโปรไฟล์ Instagram ของคุณ</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-slate-900/80 px-8 py-5 border-t border-slate-700 flex justify-end">
                                <button type="submit" name="update_profile" class="bg-gradient-to-r from-indigo-500 to-violet-500 hover:from-indigo-400 hover:to-violet-400 px-8 py-3 rounded-xl font-bold text-white transition-all shadow-lg shadow-indigo-500/30 flex items-center gap-2 transform hover:-translate-y-1">
                                    <i class="fas fa-save"></i> บันทึกข้อมูลโปรไฟล์
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Modal เพิ่ม/แก้ไขผลงาน (เดิม) -->
                    <div x-show="showModal" class="fixed inset-0 z-[100] overflow-y-auto" style="display: none;">
                        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                            <!-- Background overlay -->
                            <div x-show="showModal" x-transition.opacity class="fixed inset-0 bg-slate-900/90 backdrop-blur-sm transition-opacity" @click="showModal = false"></div>

                            <!-- Modal panel -->
                            <div x-show="showModal" x-transition.scale.origin.bottom class="relative inline-block align-bottom bg-slate-800 rounded-3xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl w-full border border-slate-700">
                                <div class="px-6 py-5 border-b border-slate-700 flex justify-between items-center bg-slate-900/30">
                                    <h3 class="text-xl font-bold font-kanit text-white flex items-center gap-2">
                                        <i class="fas fa-plus-circle text-sky-400"></i> <span x-text="editMode ? 'แก้ไขผลงาน' : 'เพิ่มผลงานใหม่'"></span>
                                    </h3>
                                    <button @click="showModal = false" class="text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 w-8 h-8 rounded-full flex items-center justify-center transition-colors"><i class="fas fa-times"></i></button>
                                </div>
                                
                                <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                                    <input type="hidden" name="item_id" x-bind:value="currentItem.id">
                                    <input type="hidden" name="existing_media_url" x-bind:value="currentItem.media_url">
                                    
                                    <div class="grid grid-cols-2 gap-5">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">ชื่อผลงาน (ไทย)</label>
                                            <input type="text" name="title_th" x-bind:value="currentItem.title_th" required class="w-full bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-sky-500 focus:ring-1 focus:ring-sky-500 outline-none transition-all">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Title (English)</label>
                                            <input type="text" name="title_en" x-bind:value="currentItem.title_en" required class="w-full bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-sky-500 focus:ring-1 focus:ring-sky-500 outline-none transition-all">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-5">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">หมวดหมู่ (Category)</label>
                                            <select name="category" x-model="currentItem.category" class="w-full bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-sky-500 outline-none transition-all cursor-pointer">
                                                <?php foreach($categories as $cat): ?>
                                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">รูปแบบไฟล์สื่อ (Type)</label>
                                            <select name="media_type" x-model="currentItem.media_type" class="w-full bg-slate-900/50 border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-sky-500 outline-none transition-all cursor-pointer">
                                                <option value="image">📸 อัปโหลดรูปภาพ (Image)</option>
                                                <option value="video_upload">🎬 อัปโหลดวิดีโอ (MP4)</option>
                                                <option value="video_embed">🔗 ใส่ลิงก์วิดีโอ (YouTube/TikTok)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div x-show="currentItem.media_type === 'image' || currentItem.media_type === 'video_upload'" class="bg-slate-900/50 p-5 rounded-2xl border border-slate-700/50 border-dashed">
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-3"><i class="fas fa-cloud-upload-alt mr-1 text-sky-400"></i> อัปโหลดไฟล์ (รูปภาพ หรือ วิดีโอ)</label>
                                        <input type="file" name="media_file" accept="image/*,video/mp4,video/webm" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2 text-white focus:border-sky-500 outline-none file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-sky-500/10 file:text-sky-400 hover:file:bg-sky-500/20 transition-all cursor-pointer" x-bind:required="!editMode && (currentItem.media_type === 'image' || currentItem.media_type === 'video_upload')">
                                        
                                        <div x-show="editMode && currentItem.media_url && currentItem.media_type !== 'video_embed'" class="mt-3 p-3 bg-slate-800 rounded-xl border border-slate-700 flex items-center justify-between">
                                            <div class="text-xs text-emerald-400 flex items-center gap-2">
                                                <i class="fas fa-check-circle"></i> มีไฟล์เดิมอยู่แล้ว ไม่ต้องอัปโหลดใหม่ (ยกเว้นต้องการเปลี่ยน)
                                            </div>
                                            <a :href="currentItem.media_url" target="_blank" class="text-xs text-sky-400 hover:text-sky-300 bg-sky-500/10 px-3 py-1.5 rounded-lg border border-sky-500/20">ดูไฟล์เดิม <i class="fas fa-external-link-alt ml-1"></i></a>
                                        </div>
                                    </div>

                                    <div x-show="currentItem.media_type === 'video_embed'" class="bg-slate-900/50 p-5 rounded-2xl border border-slate-700/50">
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2"><i class="fas fa-link mr-1 text-emerald-400"></i> ลิงก์วิดีโอ (YouTube หรือ TikTok)</label>
                                        <input type="text" name="media_url_input" x-bind:value="currentItem.media_type === 'video_embed' ? currentItem.media_url : ''" placeholder="วางลิงก์ เช่น https://youtu.be/..." class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-all" x-bind:required="currentItem.media_type === 'video_embed'">
                                        <p class="text-xs text-emerald-400 mt-2"><i class="fas fa-magic"></i> ระบบอัจฉริยะ: วางลิงก์ปกติจากมือถือได้เลย ระบบจะแปลงให้เล่นบนเว็บได้อัตโนมัติ!</p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-5 items-end">
                                        <div class="bg-slate-900/50 p-4 rounded-2xl border border-slate-700/50">
                                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">สัดส่วนการแสดงผล</label>
                                            <div class="flex gap-4">
                                                <label class="flex items-center gap-2 cursor-pointer group">
                                                    <input type="radio" name="aspect_ratio" value="16:9" x-model="currentItem.aspect_ratio" class="text-sky-500 focus:ring-sky-500 bg-slate-800 border-slate-600">
                                                    <span class="text-sm text-slate-300 group-hover:text-white transition-colors"><i class="far fa-rectangle-wide mr-1"></i> แนวนอน (16:9)</span>
                                                </label>
                                                <label class="flex items-center gap-2 cursor-pointer group">
                                                    <input type="radio" name="aspect_ratio" value="9:16" x-model="currentItem.aspect_ratio" class="text-sky-500 focus:ring-sky-500 bg-slate-800 border-slate-600">
                                                    <span class="text-sm text-slate-300 group-hover:text-white transition-colors"><i class="far fa-rectangle-portrait mr-1"></i> แนวตั้ง (9:16)</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="flex items-center gap-3 cursor-pointer text-amber-400 font-medium bg-gradient-to-r from-amber-500/10 to-orange-500/10 p-4 rounded-2xl border border-amber-500/30 hover:border-amber-500/60 transition-colors h-full">
                                                <input type="checkbox" name="is_featured" value="1" x-bind:checked="currentItem.is_featured == 1" class="rounded w-5 h-5 text-amber-500 focus:ring-amber-500 bg-slate-900 border-slate-600">
                                                <span class="flex-1">
                                                    <div class="text-sm"><i class="fas fa-star mr-1"></i> ผลงานเด่น</div>
                                                    <div class="text-[10px] text-amber-400/70 mt-0.5">(โชว์บนสุด เฉพาะ 9:16 - สูงสุด 2 ชิ้น)</div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="pt-2 flex justify-end gap-3 border-t border-slate-700 mt-6 pb-2">
                                        <button type="button" @click="showModal = false" class="px-6 py-3 rounded-xl font-bold text-slate-300 hover:bg-slate-700 transition-colors">ยกเลิก</button>
                                        <button type="submit" x-bind:name="editMode ? 'edit_item' : 'add_item'" class="bg-sky-500 hover:bg-sky-400 px-8 py-3 rounded-xl font-bold text-white transition-all shadow-lg shadow-sky-500/30 flex items-center gap-2 transform hover:-translate-y-0.5">
                                            <i class="fas fa-save"></i> <span x-text="editMode ? 'บันทึกการแก้ไข' : 'เพิ่มลงในระบบ'"></span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- สคริปต์สำหรับจัดการ Drag & Drop -->
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const tbodies = document.querySelectorAll('.sortable-tbody');
                        tbodies.forEach(tbody => {
                            new Sortable(tbody, {
                                handle: '.drag-handle',
                                animation: 200,
                                ghostClass: 'sortable-ghost',
                                easing: "cubic-bezier(1, 0, 0, 1)",
                                onEnd: function (evt) {
                                    const rows = evt.to.querySelectorAll('tr');
                                    const newOrder = [];
                                    rows.forEach(row => {
                                        if(row.getAttribute('data-id')) newOrder.push(row.getAttribute('data-id'));
                                    });

                                    const formData = new FormData();
                                    formData.append('action', 'update_order');
                                    formData.append('order', JSON.stringify(newOrder));

                                    fetch('?page=admin', {
                                        method: 'POST',
                                        body: formData
                                    }).then(response => {
                                        console.log('อัปเดตลำดับเรียบร้อย!');
                                    }).catch(error => {
                                        console.error('Error:', error);
                                    });
                                }
                            });
                        });
                    });
                </script>
            <?php endif; ?>

        <?php else: ?>
            <!-- Frontend Portfolio -->
            
            <!-- Hero Section -->
            <section class="relative pt-20 pb-16 md:pt-32 md:pb-28 overflow-hidden">
                <!-- Animated Background Blobs -->
                <div class="absolute inset-0 bg-slate-900 -z-20"></div>
                <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-sky-500/20 rounded-full blur-[100px] -z-10 translate-x-1/3 -translate-y-1/4 mix-blend-screen" data-aos="fade-in" data-aos-duration="2000"></div>
                <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-indigo-500/20 rounded-full blur-[80px] -z-10 -translate-x-1/4 translate-y-1/4 mix-blend-screen" data-aos="fade-in" data-aos-duration="2000" data-aos-delay="500"></div>
                
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
                    
                    <!-- Profile Image Alert -->
                    <div class="mb-8 relative inline-block group" data-aos="fade-down" data-aos-duration="1000">
                        <div class="w-32 h-32 md:w-40 md:h-40 rounded-full p-1.5 bg-gradient-to-tr from-sky-400 to-indigo-500 mx-auto shadow-[0_0_40px_rgba(56,189,248,0.3)] group-hover:shadow-[0_0_60px_rgba(99,102,241,0.5)] transition-shadow duration-500">
                            <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="w-full h-full object-cover rounded-full border-4 border-slate-900">
                        </div>
                    </div>

                    <div class="inline-flex items-center gap-2 mb-6 px-5 py-2 rounded-full bg-slate-800/80 border border-slate-700/50 text-sky-400 text-sm font-bold backdrop-blur-sm shadow-lg" data-aos="fade-up" data-aos-delay="200">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-sky-500"></span>
                        </span>
                        <?php echo $text['role']; ?>
                    </div>
                    
                    <h1 class="text-5xl md:text-7xl font-bold font-kanit mb-6 tracking-tight text-white" data-aos="fade-up" data-aos-delay="400">
                        <?php echo $text['name']; ?> <br class="md:hidden">
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-400 via-indigo-400 to-purple-500 inline-block transform hover:scale-105 transition-transform duration-300">
                            (<?php echo $text['nickname']; ?>)
                        </span>
                    </h1>
                    
                    <p class="text-base md:text-lg text-slate-400 max-w-2xl mx-auto mb-12 font-light leading-relaxed bg-slate-800/30 p-6 rounded-2xl border border-slate-700/50 backdrop-blur-sm" data-aos="fade-up" data-aos-delay="600">
                        <?php echo $text['hero_desc']; ?>
                    </p>
                    
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4" data-aos="fade-up" data-aos-delay="800">
                        <a href="#portfolio" class="inline-flex items-center justify-center gap-2 bg-white text-slate-900 px-8 py-3.5 rounded-full font-bold hover:bg-sky-50 transition-all transform hover:scale-105 shadow-[0_0_20px_rgba(255,255,255,0.2)] w-full sm:w-auto">
                            <i class="fas fa-play text-xs"></i> View Portfolio
                        </a>
                        <a href="#contact" class="inline-flex items-center justify-center gap-2 bg-slate-800 text-white border border-slate-700 px-8 py-3.5 rounded-full font-bold hover:bg-slate-700 hover:border-slate-500 transition-all w-full sm:w-auto">
                            <i class="fas fa-paper-plane text-xs"></i> Contact Me
                        </a>
                    </div>
                </div>
            </section>

            <!-- Featured Section (2 Items, 9:16) -->
            <?php if(count($featured_items) > 0): ?>
            <section class="py-20 bg-slate-900 border-t border-slate-800/50 relative">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-4 mb-12" data-aos="fade-right">
                        <h2 class="text-3xl font-bold font-kanit text-white flex items-center gap-3">
                            <i class="fas fa-crown text-amber-400"></i> <?php echo $text['featured']; ?>
                        </h2>
                        <div class="h-px bg-gradient-to-r from-slate-700 to-transparent flex-grow"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
                        <?php foreach($featured_items as $index => $item): ?>
                        <div data-aos="fade-up" data-aos-delay="<?php echo $index * 200; ?>" @click="showLightbox = true; lightboxType = '<?php echo $item['media_type']; ?>'; lightboxUrl = '<?php echo htmlspecialchars($item['media_url']); ?>'" class="relative group rounded-[2rem] overflow-hidden border border-slate-700 card-hover aspect-9-16 bg-slate-800 cursor-pointer shadow-2xl">
                            <?php if($item['media_type'] === 'image'): ?>
                                <img src="<?php echo htmlspecialchars($item['media_url']); ?>" alt="Featured" class="w-full h-full object-cover transition-transform duration-1000 group-hover:scale-110">
                            <?php elseif($item['media_type'] === 'video_upload'): ?>
                                <video src="<?php echo htmlspecialchars($item['media_url']); ?>" class="w-full h-full object-cover pointer-events-none transition-transform duration-1000 group-hover:scale-105" autoplay loop muted playsinline></video>
                            <?php else: ?>
                                <div class="w-full h-full relative pointer-events-none transition-transform duration-1000 group-hover:scale-105">
                                    <iframe src="<?php echo htmlspecialchars($item['media_url']); ?>?autoplay=1&mute=1&loop=1&playlist=<?php echo basename($item['media_url']); ?>&controls=0" class="absolute inset-0 w-full h-full scale-[1.3]" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>
                                    <div class="absolute inset-0 bg-transparent z-10"></div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-900/50 to-transparent opacity-90 group-hover:opacity-100 transition-opacity duration-500"></div>
                            
                            <!-- ป้าย Featured มุมบนขวา -->
                            <div class="absolute top-6 right-6">
                                <span class="bg-amber-500/90 backdrop-blur-md text-white text-xs font-bold px-4 py-1.5 rounded-full shadow-lg flex items-center gap-1.5 border border-amber-400/50">
                                    <i class="fas fa-star text-[10px]"></i> FEATURED
                                </span>
                            </div>

                            <div class="absolute bottom-0 left-0 right-0 p-8 transform translate-y-6 group-hover:translate-y-0 transition-transform duration-500">
                                <h3 class="text-3xl font-bold text-white mb-2 font-kanit leading-tight drop-shadow-md"><?php echo $lang === 'th' ? $item['title_th'] : $item['title_en']; ?></h3>
                                <p class="text-sky-400 text-sm font-bold tracking-wider uppercase mb-5 drop-shadow-md"><?php echo $item['category']; ?></p>
                                
                                <div class="flex gap-3 opacity-0 group-hover:opacity-100 transition-opacity duration-500 delay-100">
                                    <span class="inline-flex items-center text-sm font-bold text-slate-900 bg-white px-5 py-2.5 rounded-full shadow-lg hover:bg-sky-50">
                                        <?php if($item['media_type'] === 'image'): ?>
                                            <i class="fas fa-search-plus mr-2"></i> Click to Enlarge
                                        <?php else: ?>
                                            <i class="fas fa-play mr-2 text-rose-500"></i> Watch Full Video
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- All Portfolio Section (Filterable via Alpine.js) -->
            <section id="portfolio" class="py-24 bg-slate-950 relative" x-data="{ currentFilter: '<?php echo $categories[0]; ?>' }">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-16" data-aos="fade-up">
                        <h2 class="text-3xl md:text-5xl font-bold font-kanit text-white mb-8"><?php echo $text['all_works']; ?></h2>
                        
                        <!-- Filter Buttons -->
                        <div class="flex flex-wrap justify-center gap-3 bg-slate-900 p-2 rounded-2xl inline-flex border border-slate-800 shadow-xl">
                            <?php foreach($categories as $cat): ?>
                            <button @click="currentFilter = '<?php echo $cat; ?>'" 
                                    :class="currentFilter === '<?php echo $cat; ?>' ? 'bg-gradient-to-r from-sky-500 to-indigo-500 text-white shadow-lg' : 'bg-transparent text-slate-400 hover:text-white'" 
                                    class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all">
                                <?php echo $cat; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Gallery Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 xl:gap-8">
                        <?php foreach($all_items as $index => $item): ?>
                        <div x-show="currentFilter === '<?php echo $item['category']; ?>'" 
                             x-transition:enter="transition ease-out duration-500"
                             x-transition:enter-start="opacity-0 transform scale-90 translate-y-8"
                             x-transition:enter-end="opacity-100 transform scale-100 translate-y-0"
                             @click="showLightbox = true; lightboxType = '<?php echo $item['media_type']; ?>'; lightboxUrl = '<?php echo htmlspecialchars($item['media_url']); ?>'"
                             class="group relative rounded-3xl overflow-hidden bg-slate-800 border border-slate-700 card-hover <?php echo $item['aspect_ratio'] === '16:9' ? 'aspect-16-9' : 'aspect-9-16 lg:row-span-2'; ?> cursor-pointer shadow-lg"
                             data-aos="fade-up" data-aos-delay="<?php echo ($index % 3) * 100; ?>">
                            
                            <?php if($item['media_type'] === 'image'): ?>
                                <img src="<?php echo htmlspecialchars($item['media_url']); ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                            <?php elseif($item['media_type'] === 'video_upload'): ?>
                                <div class="w-full h-full relative">
                                    <video src="<?php echo htmlspecialchars($item['media_url']); ?>" class="absolute inset-0 w-full h-full object-cover pointer-events-none" autoplay loop muted playsinline></video>
                                    <div class="absolute inset-0 bg-transparent z-10"></div>
                                </div>
                            <?php else: ?>
                                <div class="w-full h-full relative pointer-events-none">
                                    <iframe src="<?php echo htmlspecialchars($item['media_url']); ?>?autoplay=1&mute=1&controls=0" class="absolute inset-0 w-full h-full scale-[1.2]" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>
                                    <div class="absolute inset-0 bg-transparent z-10"></div>
                                </div>
                            <?php endif; ?>

                            <!-- Hover Overlay -->
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/90 via-slate-900/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex flex-col justify-end p-8 z-20">
                                <div class="transform translate-y-4 group-hover:translate-y-0 transition-transform duration-300">
                                    <span class="inline-block bg-sky-500/20 border border-sky-500/30 text-sky-400 text-[10px] font-bold uppercase tracking-widest px-3 py-1 rounded-full mb-3 backdrop-blur-sm"><?php echo $item['category']; ?></span>
                                    <h3 class="text-2xl font-bold text-white mb-2 font-kanit drop-shadow-md"><?php echo $lang === 'th' ? $item['title_th'] : $item['title_en']; ?></h3>
                                    
                                    <div class="mt-4 flex items-center gap-2 text-white font-medium">
                                        <div class="w-10 h-10 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center">
                                            <?php if($item['media_type'] !== 'image'): ?>
                                                <i class="fas fa-play ml-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-search"></i>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-sm">Click to View</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ============================================================== -->
            <!-- Contact Profile Section (ช่องทางการติดต่ออัจฉริยะ) -->
            <!-- ============================================================== -->
            <section id="contact" class="py-24 bg-slate-900 relative border-t border-slate-800">
                <div class="absolute top-0 left-1/2 -translate-x-1/2 w-3/4 h-px bg-gradient-to-r from-transparent via-sky-500 to-transparent opacity-50"></div>
                
                <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                    <div class="text-center mb-12" data-aos="fade-up">
                        <h2 class="text-3xl md:text-5xl font-bold font-kanit text-white mb-4"><?php echo $text['contact_me']; ?></h2>
                        <p class="text-slate-400"></p>
                    </div>

                    <div class="bg-slate-800/50 backdrop-blur-lg rounded-[2.5rem] p-8 md:p-12 border border-slate-700 shadow-2xl" data-aos="zoom-in" data-aos-delay="200">
                        <div class="flex flex-col md:flex-row items-center gap-10">
                            
                            <!-- Profile Avatar -->
                            <div class="w-40 h-40 md:w-48 md:h-48 flex-shrink-0 relative group">
                                <div class="absolute inset-0 rounded-full bg-gradient-to-tr from-sky-400 to-indigo-500 blur-md opacity-50 group-hover:opacity-100 transition-opacity duration-500"></div>
                                <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="w-full h-full object-cover rounded-full border-[6px] border-slate-800 relative z-10 transform group-hover:scale-105 transition-transform duration-500">
                                <div class="absolute bottom-2 right-2 w-8 h-8 bg-emerald-500 border-4 border-slate-800 rounded-full z-20" title="Online & Ready to work"></div>
                            </div>
                            
                            <!-- Info & Social Links -->
                            <div class="flex-grow text-center md:text-left">
                                <h3 class="text-3xl font-bold text-white mb-2 font-kanit"><?php echo $text['name']; ?></h3>
                                <p class="text-sky-400 font-medium mb-6 uppercase tracking-widest text-sm"><?php echo $text['role']; ?></p>
                                
                                <div class="flex flex-col sm:flex-row gap-4 justify-center md:justify-start">
                                    
                                    <!-- Facebook Button Widget -->
                                    <?php if(!empty($fb_url)): ?>
                                    <a href="<?php echo htmlspecialchars($fb_url); ?>" target="_blank" class="flex items-center p-1 pr-6 bg-slate-900 rounded-full border border-slate-700 hover:border-blue-500 group transition-all shadow-lg hover:-translate-y-1">
                                        <div class="w-12 h-12 rounded-full bg-blue-600 text-white flex items-center justify-center text-xl mr-4 group-hover:scale-110 transition-transform">
                                            <i class="fab fa-facebook-f"></i>
                                        </div>
                                        <div>
                                            <div class="text-[10px] text-slate-400 uppercase tracking-wider mb-0.5">Contact via</div>
                                            <div class="font-bold text-white text-sm">Facebook</div>
                                        </div>
                                        <i class="fas fa-external-link-alt ml-auto pl-4 text-slate-600 group-hover:text-blue-400 transition-colors"></i>
                                    </a>
                                    <?php endif; ?>

                                    <!-- Instagram Button Widget -->
                                    <?php if(!empty($ig_url)): ?>
                                    <a href="<?php echo htmlspecialchars($ig_url); ?>" target="_blank" class="flex items-center p-1 pr-6 bg-slate-900 rounded-full border border-slate-700 hover:border-pink-500 group transition-all shadow-lg hover:-translate-y-1">
                                        <div class="w-12 h-12 rounded-full ig-gradient text-white flex items-center justify-center text-xl mr-4 group-hover:scale-110 transition-transform">
                                            <i class="fab fa-instagram"></i>
                                        </div>
                                        <div>
                                            <div class="text-[10px] text-slate-400 uppercase tracking-wider mb-0.5">Follow me on</div>
                                            <div class="font-bold text-white text-sm">Instagram</div>
                                        </div>
                                        <i class="fas fa-external-link-alt ml-auto pl-4 text-slate-600 group-hover:text-pink-400 transition-colors"></i>
                                    </a>
                                    <?php endif; ?>

                                    <?php if(empty($fb_url) && empty($ig_url)): ?>
                                        <p class="text-sm text-slate-500">กรุณาเพิ่มลิงก์ติดต่อในระบบ Admin</p>
                                    <?php endif; ?>

                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 py-10 border-t border-slate-800 mt-auto relative z-10">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <div class="w-12 h-12 rounded-full bg-gradient-to-tr from-sky-500 to-indigo-500 flex items-center justify-center mx-auto mb-6 text-white font-bold text-lg shadow-lg">B</div>
            <p class="text-slate-500 text-sm font-medium tracking-wide"><?php echo $text['footer']; ?></p>
        </div>
    </footer>

    <!-- Lightbox Modal (ระบบขยายรูปและดูวิดีโอ) -->
    <div x-show="showLightbox" x-cloak class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/95 backdrop-blur-xl transition-opacity duration-300">
        <!-- ปุ่มปิดดีไซน์ใหม่ -->
        <button @click="showLightbox = false; lightboxUrl = ''" class="absolute top-6 right-6 w-12 h-12 bg-white/10 hover:bg-rose-500 rounded-full text-white text-2xl z-[120] transition-all flex items-center justify-center backdrop-blur-md border border-white/20 hover:scale-110 focus:outline-none">
            <i class="fas fa-times"></i>
        </button>
        
        <div @click.away="showLightbox = false; lightboxUrl = ''" class="max-w-7xl w-full max-h-[90vh] p-4 flex justify-center items-center relative"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform scale-90"
             x-transition:enter-end="opacity-100 transform scale-100">
            
            <!-- กรณีเปิดรูปภาพ -->
            <template x-if="lightboxType === 'image'">
                <img :src="lightboxUrl" class="max-w-full max-h-[85vh] object-contain rounded-2xl shadow-[0_0_60px_rgba(0,0,0,0.8)] border border-slate-700/50">
            </template>
            
            <!-- กรณีเปิดวิดีโอ (อัปโหลด) -->
            <template x-if="lightboxType === 'video_upload'">
                <video :src="lightboxUrl" controls autoplay class="max-w-full max-h-[85vh] rounded-2xl shadow-[0_0_60px_rgba(0,0,0,0.8)] border border-slate-700/50 outline-none w-full object-contain bg-black"></video>
            </template>

            <!-- กรณีเปิดวิดีโอ (Embed ลิงก์) -->
            <template x-if="lightboxType === 'video_embed'">
                <div class="w-full aspect-video max-w-5xl relative rounded-2xl overflow-hidden shadow-[0_0_60px_rgba(0,0,0,0.8)] border border-slate-700/50 bg-black">
                    <iframe :src="lightboxUrl + '?autoplay=1'" class="absolute inset-0 w-full h-full" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>
                </div>
            </template>
            
        </div>
    </div>

    <!-- สคริปต์เปิดใช้งาน AOS Animation -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            AOS.init({
                once: true, // แสดงแอนิเมชันครั้งเดียวตอนเลื่อนลงมา
                offset: 100, // ระยะเลื่อนจอก่อนแสดงแอนิเมชัน
            });
        });
    </script>

</body>
</html>
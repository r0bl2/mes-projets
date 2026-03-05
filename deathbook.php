<?php
/* ===========================================
   SECTION 1 : INITIALISATION ET SESSION
=========================================== */
session_start();

/* ===========================================
   SECTION 2 : CONFIGURATION
=========================================== */
define('SITE_NAME', 'W-ASSIST');
define('UPLOAD_DIR', 'uploads/');
define('SECRET_KEY', 'WASSIST');

/* ===========================================
   SECTION 3 : CONNEXION À LA BASE DE DONNÉES
=========================================== */
try {
    $pdo = new PDO("pgsql:host=localhost;dbname=langageC", "postgres", "postgresql");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

/* ===========================================
   SECTION 4 : CRÉATION DES TABLES
=========================================== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS users(
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    avatar VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP
);

CREATE TABLE IF NOT EXISTS temoignages(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    categorie VARCHAR(50),
    likes INT DEFAULT 0,
    date_pub TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved BOOLEAN DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS demandes_aide(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    situation VARCHAR(100) NOT NULL,
    contact VARCHAR(100) NOT NULL,
    circonstances TEXT NOT NULL,
    urgence VARCHAR(20) DEFAULT 'moyenne',
    status VARCHAR(20) DEFAULT 'en_attente',
    date_demande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_traitement TIMESTAMP
);

CREATE TABLE IF NOT EXISTS articles(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    titre VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    prix NUMERIC NOT NULL,
    categorie VARCHAR(50),
    image VARCHAR(255),
    vendu BOOLEAN DEFAULT FALSE,
    date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS discussions(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    media VARCHAR(255),
    type_media VARCHAR(20),
    likes INT DEFAULT 0,
    date_msg TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages_prives(
    id SERIAL PRIMARY KEY,
    sender_id INT REFERENCES users(id) ON DELETE CASCADE,
    receiver_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    lu BOOLEAN DEFAULT FALSE,
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    type VARCHAR(30),
    lu BOOLEAN DEFAULT FALSE,
    date_notif TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

/* ===========================================
   SECTION 5 : FONCTIONS UTILITAIRES
=========================================== */

/**
 * Gère l'upload de fichiers
 */
function uploadFile($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'webm']) {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }
    
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowedTypes)) {
        return false;
    }
    
    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        return $filename;
    }
    
    return false;
}

/**
 * Crée une notification
 */
function createNotification($pdo, $userId, $message, $type = 'info') {
    $stmt = $pdo->prepare("INSERT INTO notifications(user_id, message, type) VALUES (?, ?, ?)");
    return $stmt->execute([$userId, $message, $type]);
}

/**
 * Formate la date
 */
function formatDate($date) {
    $now = new DateTime();
    $dateObj = new DateTime($date);
    $interval = $now->diff($dateObj);
    
    if ($interval->d == 0) {
        if ($interval->h == 0) {
            if ($interval->i < 1) return "À l'instant";
            return "Il y a " . $interval->i . " min";
        }
        return "Il y a " . $interval->h . " h";
    }
    
    if ($interval->d == 1) return "Hier";
    if ($interval->d < 7) return "Il y a " . $interval->d . " jours";
    
    return $dateObj->format('d/m/Y H:i');
}

/* ===========================================
   SECTION 6 : ROUTEUR ET TRAITEMENT DES REQUÊTES
=========================================== */
$page = $_GET['page'] ?? 'login';
$action = $_GET['action'] ?? null;

/* ===========================================
   SECTION 7 : TRAITEMENT DES FORMULAIRES
=========================================== */

// 7.1 : Inscription
if (isset($_POST['register'])) {
    $nom = trim($_POST['nom']);
    $email = trim($_POST['email']);
    
    if ($nom && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['error'] = 'Cet email est déjà utilisé.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO users(nom, email) VALUES(?, ?)");
                $stmt->execute([$nom, $email]);
                
                $_SESSION['success'] = 'Compte créé avec succès !';
                header('Location: ?page=login');
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Une erreur est survenue.';
        }
    } else {
        $_SESSION['error'] = 'Veuillez remplir tous les champs correctement.';
    }
}

// 7.2 : Connexion
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        $_SESSION['user'] = $user;
        createNotification($pdo, $user['id'], 'Bienvenue sur W-ASSIST !', 'welcome');
        header('Location: ?page=dashboard');
        exit;
    } else {
        $_SESSION['error'] = 'Email inconnu. Veuillez créer un compte.';
    }
}

// 7.3 : Déconnexion
if ($page == 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// 7.4 : Témoignage
if (isset($_POST['add_temoignage']) && isset($_SESSION['user'])) {
    $message = trim($_POST['message']);
    $categorie = $_POST['categorie'] ?? null;
    
    if ($message) {
        $stmt = $pdo->prepare("INSERT INTO temoignages(user_id, message, categorie) VALUES(?, ?, ?)");
        if ($stmt->execute([$_SESSION['user']['id'], $message, $categorie])) {
            $_SESSION['success'] = 'Témoignage publié avec succès !';
            header('Location: ?page=temoignages');
            exit;
        }
    }
}

// 7.5 : Like témoignage
if ($action == 'like_temoignage' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE temoignages SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// 7.6 : Demande aide
if (isset($_POST['add_aide']) && isset($_SESSION['user'])) {
    $situation = trim($_POST['situation']);
    $contact = trim($_POST['contact']);
    $circonstances = trim($_POST['circonstances']);
    $urgence = $_POST['urgence'] ?? 'moyenne';
    
    if ($situation && $contact && $circonstances) {
        $stmt = $pdo->prepare("INSERT INTO demandes_aide(user_id, situation, contact, circonstances, urgence) VALUES(?, ?, ?, ?, ?)");
        if ($stmt->execute([$_SESSION['user']['id'], $situation, $contact, $circonstances, $urgence])) {
            createNotification($pdo, $_SESSION['user']['id'], 'Votre demande d\'aide a été enregistrée.', 'aide');
            $_SESSION['success'] = 'Demande envoyée avec succès !';
            header('Location: ?page=aide');
            exit;
        }
    }
}

// 7.7 : Articles
if (isset($_POST['add_article']) && isset($_SESSION['user'])) {
    $titre = trim($_POST['titre']);
    $description = trim($_POST['description']);
    $prix = is_numeric($_POST['prix']) ? (float)$_POST['prix'] : 0;
    $categorie = $_POST['categorie'] ?? null;
    $secret = trim($_POST['secret']);
    
    $image = null;
    if (!empty($_FILES['image']['name'])) {
        $image = uploadFile($_FILES['image'], ['jpg', 'jpeg', 'png', 'gif']);
    }
    
    if ($titre && $description && $prix > 0) {
        if ($secret === SECRET_KEY) {
            $stmt = $pdo->prepare("INSERT INTO articles(user_id, titre, description, prix, categorie, image) VALUES(?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user']['id'], $titre, $description, $prix, $categorie, $image])) {
                $_SESSION['success'] = 'Article ajouté avec succès !';
                header('Location: ?page=articles');
                exit;
            }
        } else {
            $_SESSION['error'] = 'Mot secret incorrect.';
        }
    } else {
        $_SESSION['error'] = 'Veuillez remplir tous les champs correctement.';
    }
}

// 7.8 : Marquer article comme vendu
if ($action == 'mark_sold' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE articles SET vendu = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user']['id']]);
        header('Location: ?page=articles');
        exit;
    }
}

// 7.9 : Discussion avec media
if (isset($_POST['add_discussion']) && isset($_SESSION['user'])) {
    $message = trim($_POST['message']);
    
    $media = null;
    $type = null;
    
    if (!empty($_FILES['media']['name'])) {
        $media = uploadFile($_FILES['media']);
        if ($media) {
            $ext = strtolower(pathinfo($media, PATHINFO_EXTENSION));
            $type = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) ? 'image' : 'video';
        }
    }
    
    if ($message) {
        $stmt = $pdo->prepare("INSERT INTO discussions(user_id, message, media, type_media) VALUES(?, ?, ?, ?)");
        if ($stmt->execute([$_SESSION['user']['id'], $message, $media, $type])) {
            header('Location: ?page=discussion');
            exit;
        }
    }
}

// 7.10 : Like message discussion
if ($action == 'like_message' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE discussions SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// 7.11 : Message privé
if (isset($_POST['send_message']) && isset($_SESSION['user'])) {
    $receiver_id = $_POST['receiver_id'] ?? 0;
    $message = trim($_POST['message']);
    
    if ($receiver_id && $message) {
        $stmt = $pdo->prepare("INSERT INTO messages_prives(sender_id, receiver_id, message) VALUES(?, ?, ?)");
        if ($stmt->execute([$_SESSION['user']['id'], $receiver_id, $message])) {
            createNotification($pdo, $receiver_id, 'Vous avez reçu un nouveau message', 'message');
            $_SESSION['success'] = 'Message envoyé avec succès !';
            header('Location: ?page=messages&user=' . $receiver_id);
            exit;
        }
    }
}

// 7.12 : Marquer notification comme lue
if ($action == 'read_notification' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE notifications SET lu = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user']['id']]);
        header('Location: ?page=dashboard');
        exit;
    }
}

/* ===========================================
   SECTION 8 : HTML - STRUCTURE PRINCIPALE
=========================================== */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> | Plateforme d'Assistance aux Femmes</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ===========================================
           STYLE GRIS/BLANC ÉPURÉ
        =========================================== */
        :root {
            --bg-primary: #f8f9fa;
            --bg-secondary: #ffffff;
            --text-primary: #2d3436;
            --text-secondary: #636e72;
            --text-muted: #b2bec3;
            --border-color: #e9ecef;
            --border-light: #f1f3f5;
            --accent-color: #0984e3;
            --accent-hover: #0873c4;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.05);
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
        }
        
        .app-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .header {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .main-content {
            flex: 1;
            max-width: 1400px;
            width: 100%;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .footer {
            background-color: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            padding: 2rem;
            margin-top: auto;
            color: var(--text-secondary);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .logo-icon {
            font-size: 1.5rem;
            color: var(--accent-color);
        }
        
        h1 { font-size: 2rem; font-weight: 600; margin-bottom: 1rem; }
        h2 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; }
        h3 { font-size: 1.25rem; font-weight: 500; margin-bottom: 0.75rem; }
        
        .card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--border-light);
        }
        
        .btn-primary {
            background-color: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
        }
        
        .btn-primary:hover {
            background-color: var(--accent-hover);
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            background-color: var(--bg-secondary);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23636e72' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }
        
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            border: 1px solid transparent;
            font-size: 0.875rem;
        }
        
        .alert-success {
            background-color: rgba(0, 184, 148, 0.1);
            color: var(--success);
            border-color: rgba(0, 184, 148, 0.2);
        }
        
        .alert-error {
            background-color: rgba(214, 48, 49, 0.1);
            color: var(--danger);
            border-color: rgba(214, 48, 49, 0.2);
        }
        
        .nav-menu {
            display: flex;
            gap: 0.25rem;
            align-items: center;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-link:hover {
            background-color: var(--border-light);
            color: var(--text-primary);
        }
        
        .nav-link.active {
            background-color: var(--border-light);
            color: var(--accent-color);
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-left: 1rem;
            padding-left: 1rem;
            border-left: 1px solid var(--border-color);
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            background-color: var(--border-light);
            color: var(--text-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-sidebar {
            flex: 0 0 280px;
        }
        
        .filter-section {
            margin-bottom: 1.5rem;
        }
        
        .filter-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }
        
        .filter-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            cursor: pointer;
        }
        
        .filter-option input[type="radio"] {
            accent-color: var(--accent-color);
        }
        
        .article-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        
        .article-image {
            height: 160px;
            background-color: var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }
        
        .article-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .article-content {
            padding: 1rem;
        }
        
        .article-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .article-category {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .article-price {
            font-weight: 600;
            color: var(--accent-color);
            font-size: 1.1rem;
            margin: 0.5rem 0;
        }
        
        .testimonial-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .testimonial-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .like-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
        }
        
        .like-btn:hover {
            color: var(--danger);
        }
        
        .help-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1rem;
            border-top: 3px solid;
        }
        
        .help-card.urgence-haute { border-top-color: var(--danger); }
        .help-card.urgence-moyenne { border-top-color: var(--warning); }
        .help-card.urgence-basse { border-top-color: var(--success); }
        
        .urgence-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background-color: var(--border-light);
        }
        
        .products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .products-count {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .divider {
            height: 1px;
            background-color: var(--border-color);
            margin: 1rem 0;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-sidebar {
                flex: 0 0 100%;
            }
        }
    </style>
</head>
<body>
<div class="app-container">
    <!-- EN-TÊTE SANS FLEUR ROSE -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <span><?= SITE_NAME ?></span>
            </div>
            
            <nav class="nav-menu">
                <?php if (isset($_SESSION['user'])): ?>
                    <a href="?page=dashboard" class="nav-link <?= $page == 'dashboard' ? 'active' : '' ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    <a href="?page=temoignages" class="nav-link <?= $page == 'temoignages' ? 'active' : '' ?>">
                        <i class="fas fa-comments"></i> Témoignages
                    </a>
                    <a href="?page=aide" class="nav-link <?= $page == 'aide' ? 'active' : '' ?>">
                        <i class="fas fa-hands-helping"></i> Demande d'aide
                    </a>
                    <a href="?page=articles" class="nav-link <?= $page == 'articles' ? 'active' : '' ?>">
                        <i class="fas fa-shopping-bag"></i> Articles
                    </a>
                    <a href="?page=discussion" class="nav-link <?= $page == 'discussion' ? 'active' : '' ?>">
                        <i class="fas fa-comment-dots"></i> Discussion
                    </a>
                    
                    <?php
                    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = FALSE");
                    $notifStmt->execute([$_SESSION['user']['id']]);
                    $notifCount = $notifStmt->fetchColumn();
                    ?>
                    
                    <div class="user-menu">
                        <a href="?page=notifications" class="nav-link">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifCount > 0): ?>
                            <span style="background-color: var(--accent-color); color: white; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem;"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="user-avatar">
                            <?= strtoupper(substr($_SESSION['user']['nom'], 0, 1)) ?>
                        </div>
                        <span style="font-size: 0.875rem;"><?= htmlspecialchars($_SESSION['user']['nom']) ?></span>
                        <a href="?page=logout" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <a href="?page=temoignages" class="nav-link <?= $page == 'temoignages' ? 'active' : '' ?>">
                        <i class="fas fa-comments"></i> Témoignages
                    </a>
                    <a href="?page=aide" class="nav-link <?= $page == 'aide' ? 'active' : '' ?>">
                        <i class="fas fa-hands-helping"></i> Demande d'aide
                    </a>
                    <a href="?page=articles" class="nav-link <?= $page == 'articles' ? 'active' : '' ?>">
                        <i class="fas fa-shopping-bag"></i> Articles
                    </a>
                    <a href="?page=discussion" class="nav-link <?= $page == 'discussion' ? 'active' : '' ?>">
                        <i class="fas fa-comment-dots"></i> Discussion
                    </a>
                    <a href="?page=login" class="btn btn-primary" style="margin-left: 1rem;">
                        <i class="fas fa-sign-in-alt"></i> Se connecter
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- CONTENU PRINCIPAL -->
    <main class="main-content">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $_SESSION['success'] ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $_SESSION['error'] ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- PAGE DE CONNEXION -->
        <?php if ($page == 'login'): ?>
            <div class="card" style="max-width: 400px; margin: 4rem auto;">
                <h2 style="text-align: center;">Connexion</h2>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary" style="width: 100%;">Se connecter</button>
                </form>
                <div class="divider"></div>
                <p style="text-align: center; font-size: 0.875rem;">
                    Pas encore de compte ? <a href="?page=register" style="color: var(--accent-color);">S'inscrire</a>
                </p>
            </div>
        
        <!-- PAGE D'INSCRIPTION -->
        <?php elseif ($page == 'register'): ?>
            <div class="card" style="max-width: 400px; margin: 4rem auto;">
                <h2 style="text-align: center;">Inscription</h2>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Nom complet</label>
                        <input type="text" name="nom" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <button type="submit" name="register" class="btn btn-primary" style="width: 100%;">S'inscrire</button>
                </form>
                <div class="divider"></div>
                <p style="text-align: center; font-size: 0.875rem;">
                    Déjà un compte ? <a href="?page=login" style="color: var(--accent-color);">Se connecter</a>
                </p>
            </div>
        
        <!-- DASHBOARD -->
        <?php elseif ($page == 'dashboard' && isset($_SESSION['user'])):
            $userId = $_SESSION['user']['id'];
            $testimonialsCount = $pdo->prepare("SELECT COUNT(*) FROM temoignages WHERE user_id = ?");
            $testimonialsCount->execute([$userId]);
            $testimonialsCount = $testimonialsCount->fetchColumn();

            $helpRequestsCount = $pdo->prepare("SELECT COUNT(*) FROM demandes_aide WHERE user_id = ?");
            $helpRequestsCount->execute([$userId]);
            $helpRequestsCount = $helpRequestsCount->fetchColumn();

            $articlesCount = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE user_id = ?");
            $articlesCount->execute([$userId]);
            $articlesCount = $articlesCount->fetchColumn();
            ?>
            
            <div style="margin-bottom: 2rem;">
                <h1>Bonjour, <?= htmlspecialchars($_SESSION['user']['nom']) ?></h1>
                <p style="color: var(--text-secondary);">Bienvenue sur votre tableau de bord</p>
            </div>
            
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-number"><?= $testimonialsCount ?></div>
                    <div class="stat-label">Témoignages</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $helpRequestsCount ?></div>
                    <div class="stat-label">Demandes d'aide</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $articlesCount ?></div>
                    <div class="stat-label">Articles</div>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="?page=temoignages" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau témoignage</a>
                <a href="?page=aide" class="btn"><i class="fas fa-hands-helping"></i> Demander de l'aide</a>
                <a href="?page=articles" class="btn"><i class="fas fa-shopping-bag"></i> Vendre un article</a>
                <a href="?page=discussion" class="btn"><i class="fas fa-comment-dots"></i> Discussion</a>
            </div>
        
        <!-- PAGE TÉMOIGNAGES -->
        <?php elseif ($page == 'temoignages' && isset($_SESSION['user'])): ?>
            <h2>Témoignages</h2>
            
            <!-- Formulaire d'ajout -->
            <div class="card">
                <h3 class="card-title">Partagez votre expérience</h3>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Catégorie</label>
                        <select name="categorie" class="form-control">
                            <option value="">Choisir une catégorie</option>
                            <option value="soutien">Soutien moral</option>
                            <option value="grossesse">Grossesse</option>
                            <option value="violence">Violence conjugale</option>
                            <option value="professionnel">Défis professionnels</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Votre témoignage</label>
                        <textarea name="message" class="form-control" placeholder="Partagez votre histoire..." required></textarea>
                    </div>
                    <button type="submit" name="add_temoignage" class="btn btn-primary">Publier</button>
                </form>
            </div>
            
            <!-- Liste des témoignages -->
            <h3>Témoignages de la communauté</h3>
            <?php
            $testimonials = $pdo->query("
                SELECT t.*, u.nom 
                FROM temoignages t 
                JOIN users u ON u.id = t.user_id 
                WHERE t.approved = TRUE 
                ORDER BY t.date_pub DESC
            ");
            
            while ($t = $testimonials->fetch()):
            ?>
            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="testimonial-author">
                        <div class="user-avatar"><?= strtoupper(substr($t['nom'], 0, 1)) ?></div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($t['nom']) ?></div>
                            <?php if ($t['categorie']): ?>
                            <div style="font-size: 0.7rem; color: var(--accent-color);">#<?= htmlspecialchars($t['categorie']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= formatDate($t['date_pub']) ?></div>
                </div>
                <div style="margin-bottom: 1rem;"><?= nl2br(htmlspecialchars($t['message'])) ?></div>
                <button class="like-btn" onclick="likeTestimonial(<?= $t['id'] ?>)">
                    <i class="fas fa-heart"></i> <?= $t['likes'] ?>
                </button>
            </div>
            <?php endwhile; ?>
        
        <!-- PAGE DEMANDE D'AIDE -->
        <?php elseif ($page == 'aide' && isset($_SESSION['user'])): ?>
            <h2>Demande d'aide</h2>
            
            <div class="card">
                <h3 class="card-title">Formulaire de demande d'assistance</h3>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Situation</label>
                        <select name="situation" class="form-control" required>
                            <option value="">Sélectionnez votre situation</option>
                            <option value="Grossesse non désirée">Grossesse non désirée</option>
                            <option value="Viol">Viol</option>
                            <option value="Violence conjugale">Violence conjugale</option>
                            <option value="Harcèlement moral">Harcèlement moral</option>
                            <option value="Difficultés financières">Difficultés financières</option>
                            <option value="Isolement social">Isolement social</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Niveau d'urgence</label>
                        <select name="urgence" class="form-control" required>
                            <option value="basse">Basse</option>
                            <option value="moyenne" selected>Moyenne</option>
                            <option value="haute">Haute</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact</label>
                        <input type="text" name="contact" class="form-control" placeholder="Téléphone ou email" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="circonstances" class="form-control" rows="4" required></textarea>
                    </div>
                    
                    <button type="submit" name="add_aide" class="btn btn-primary">Envoyer la demande</button>
                </form>
            </div>
            
            <h3>Vos demandes précédentes</h3>
            <?php
            $demandes = $pdo->prepare("SELECT * FROM demandes_aide WHERE user_id = ? ORDER BY date_demande DESC");
            $demandes->execute([$_SESSION['user']['id']]);
            
            while ($d = $demandes->fetch()):
                $urgenceClass = 'urgence-' . $d['urgence'];
            ?>
            <div class="help-card <?= $urgenceClass ?>">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <h4 style="margin: 0;"><?= htmlspecialchars($d['situation']) ?></h4>
                    <span class="urgence-badge"><?= $d['urgence'] ?></span>
                </div>
                <div style="margin-bottom: 0.5rem; font-size: 0.875rem;"><?= nl2br(htmlspecialchars($d['circonstances'])) ?></div>
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-secondary);">
                    <span>Statut: <?= $d['status'] ?></span>
                    <span><?= formatDate($d['date_demande']) ?></span>
                </div>
            </div>
            <?php endwhile; ?>
        
        <!-- PAGE ARTICLES -->
        <?php elseif ($page == 'articles' && isset($_SESSION['user'])):
            // Récupérer les filtres
            $search = $_GET['search'] ?? '';
            $categorie = $_GET['categorie'] ?? '';
            $prix_range = $_GET['prix'] ?? '';
            $sort = $_GET['sort'] ?? 'recent';
            
            $sql = "SELECT a.*, u.nom FROM articles a JOIN users u ON u.id = a.user_id WHERE a.vendu = FALSE";
            $params = [];
            
            if (!empty($search)) {
                $sql .= " AND (a.titre ILIKE ? OR a.description ILIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            if (!empty($categorie)) {
                $sql .= " AND a.categorie = ?";
                $params[] = $categorie;
            }
            
            if (!empty($prix_range)) {
                switch($prix_range) {
                    case 'moins_15000': $sql .= " AND a.prix < 15000"; break;
                    case '15000_30000': $sql .= " AND a.prix BETWEEN 15000 AND 30000"; break;
                    case '30000_60000': $sql .= " AND a.prix BETWEEN 30000 AND 60000"; break;
                    case '60000_120000': $sql .= " AND a.prix BETWEEN 60000 AND 120000"; break;
                }
            }
            
            switch($sort) {
                case 'recent': $sql .= " ORDER BY a.date_ajout DESC"; break;
                case 'ancien': $sql .= " ORDER BY a.date_ajout ASC"; break;
                case 'prix_croissant': $sql .= " ORDER BY a.prix ASC"; break;
                case 'prix_decroissant': $sql .= " ORDER BY a.prix DESC"; break;
                default: $sql .= " ORDER BY a.date_ajout DESC";
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $articles = $stmt->fetchAll();
            $total_articles = count($articles);
            ?>
            
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                <!-- Sidebar filtres -->
                <div class="filter-sidebar">
                    <div class="card" style="position: sticky; top: 100px;">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Filtres</h3>
                        
                        <div class="filter-section">
                            <div class="filter-title">Recherché</div>
                            <form method="get" id="filter-form">
                                <input type="hidden" name="page" value="articles">
                                <div style="position: relative;">
                                    <i class="fas fa-search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                                    <input type="text" name="search" placeholder="Nom du produit..." 
                                           value="<?= htmlspecialchars($search) ?>"
                                           style="width: 100%; padding: 0.5rem 0.5rem 0.5rem 2rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                                </div>
                            </form>
                        </div>
                        
                        <div class="filter-section">
                            <div class="filter-title">Catégories</div>
                            <select name="categorie" form="filter-form" class="form-control" onchange="this.form.submit()">
                                <option value="">Toutes les catégories</option>
                                <option value="vetements" <?= $categorie == 'vetements' ? 'selected' : '' ?>>Vêtements</option>
                                <option value="chaussures" <?= $categorie == 'chaussures' ? 'selected' : '' ?>>Chaussures</option>
                                <option value="accessoires" <?= $categorie == 'accessoires' ? 'selected' : '' ?>>Accessoires</option>
                                <option value="cosmetiques" <?= $categorie == 'cosmetiques' ? 'selected' : '' ?>>Cosmétiques</option>
                                <option value="livres" <?= $categorie == 'livres' ? 'selected' : '' ?>>Livres</option>
                            </select>
                        </div>
                        
                        <div class="filter-section">
                            <div class="filter-title">Prix</div>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <label class="filter-option">
                                    <input type="radio" name="prix" value="moins_15000" form="filter-form" 
                                           <?= $prix_range == 'moins_15000' ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span>Moins de 15 000 FCFA</span>
                                </label>
                                <label class="filter-option">
                                    <input type="radio" name="prix" value="15000_30000" form="filter-form" 
                                           <?= $prix_range == '15000_30000' ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span>15 000 - 30 000 FCFA</span>
                                </label>
                                <label class="filter-option">
                                    <input type="radio" name="prix" value="30000_60000" form="filter-form" 
                                           <?= $prix_range == '30000_60000' ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span>30 000 - 60 000 FCFA</span>
                                </label>
                                <label class="filter-option">
                                    <input type="radio" name="prix" value="60000_120000" form="filter-form" 
                                           <?= $prix_range == '60000_120000' ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span>60 000 - 120 000 FCFA</span>
                                </label>
                            </div>
                        </div>
                        
                        <a href="?page=articles" class="btn" style="width: 100%;"><i class="fas fa-undo"></i> Réinitialiser</a>
                    </div>
                </div>
                
                <!-- Liste des articles -->
                <div style="flex: 1;">
                    <div class="products-header">
                        <div>
                            <h2 style="margin: 0; font-size: 1.25rem;">Nos produits</h2>
                            <p class="products-count"><?= $total_articles ?> produit(s) trouvé(s)</p>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-size: 0.875rem; color: var(--text-secondary);">Trier par :</span>
                            <select name="sort" form="filter-form" class="form-control" style="width: auto; min-width: 140px;" onchange="this.form.submit()">
                                <option value="recent" <?= $sort == 'recent' ? 'selected' : '' ?>>Plus récemment</option>
                                <option value="ancien" <?= $sort == 'ancien' ? 'selected' : '' ?>>Plus ancien</option>
                                <option value="prix_croissant" <?= $sort == 'prix_croissant' ? 'selected' : '' ?>>Prix croissant</option>
                                <option value="prix_decroissant" <?= $sort == 'prix_decroissant' ? 'selected' : '' ?>>Prix décroissant</option>
                            </select>
                        </div>
                    </div>
                    
                    <form id="filter-form" method="get" style="display: none;">
                        <input type="hidden" name="page" value="articles">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="categorie" value="<?= htmlspecialchars($categorie) ?>">
                        <input type="hidden" name="prix" value="<?= htmlspecialchars($prix_range) ?>">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    </form>
                    
                    <?php if ($total_articles == 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Aucun produit trouvé</h3>
                        <p style="color: var(--text-secondary);">Essayez de modifier vos filtres</p>
                        <button onclick="document.getElementById('add-product-form').style.display = 'block'" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Ajouter un produit
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-3">
                        <?php foreach ($articles as $a): ?>
                        <div class="article-card">
                            <?php if ($a['image']): ?>
                            <div class="article-image">
                                <img src="<?= UPLOAD_DIR . htmlspecialchars($a['image']) ?>" alt="<?= htmlspecialchars($a['titre']) ?>">
                            </div>
                            <?php else: ?>
                            <div class="article-image">
                                <i class="fas fa-image"></i>
                            </div>
                            <?php endif; ?>
                            
                            <div class="article-content">
                                <div class="article-title"><?= htmlspecialchars($a['titre']) ?></div>
                                <?php if ($a['categorie']): ?>
                                <div class="article-category"><?= htmlspecialchars($a['categorie']) ?></div>
                                <?php endif; ?>
                                <div class="article-price"><?= number_format($a['prix'], 0, ',', ' ') ?> FCFA</div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);">Par <?= htmlspecialchars($a['nom']) ?></div>
                                
                                <?php if ($a['user_id'] == $_SESSION['user']['id']): ?>
                                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                    <a href="?page=articles&action=mark_sold&id=<?= $a['id'] ?>" class="btn btn-sm" style="width: 100%;" onclick="return confirm('Marquer comme vendu ?')">
                                        <i class="fas fa-check"></i> Marquer vendu
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="text-align: center; margin-top: 2rem;">
                        <button onclick="document.getElementById('add-product-form').style.display = 'block'" class="btn">
                            <i class="fas fa-plus"></i> Ajouter un produit
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Formulaire d'ajout d'article -->
                    <div id="add-product-form" style="display: none; margin-top: 2rem;">
                        <div class="card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h3 style="font-size: 1rem;">Vendre un article</h3>
                                <button onclick="this.parentElement.parentElement.parentElement.style.display = 'none'" style="background: none; border: none; cursor: pointer;">&times;</button>
                            </div>
                            
                            <form method="post" enctype="multipart/form-data">
                                <div class="grid grid-2" style="gap: 1rem;">
                                    <div class="form-group">
                                        <label class="form-label">Titre</label>
                                        <input type="text" name="titre" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Prix (FCFA)</label>
                                        <input type="number" name="prix" class="form-control" min="0" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Catégorie</label>
                                    <select name="categorie" class="form-control">
                                        <option value="">Choisir</option>
                                        <option value="vetements">Vêtements</option>
                                        <option value="chaussures">Chaussures</option>
                                        <option value="accessoires">Accessoires</option>
                                        <option value="cosmetiques">Cosmétiques</option>
                                        <option value="livres">Livres</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Image</label>
                                    <input type="file" name="image" accept="image/*" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Mot secret</label>
                                    <input type="password" name="secret" class="form-control" required>
                                </div>
                                
                                <button type="submit" name="add_article" class="btn btn-primary">Ajouter l'article</button>
                                <button type="button" onclick="this.closest('#add-product-form').style.display = 'none'" class="btn" style="margin-left: 0.5rem;">Annuler</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        
        <!-- PAGE DISCUSSION -->
        <?php elseif ($page == 'discussion' && isset($_SESSION['user'])): ?>
            <h2>Discussion</h2>
            
            <div class="card">
                <h3 class="card-title">Nouveau message</h3>
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <textarea name="message" class="form-control" placeholder="Votre message..." rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Média (optionnel)</label>
                        <input type="file" name="media" accept="image/*,video/*" class="form-control">
                    </div>
                    <button type="submit" name="add_discussion" class="btn btn-primary">Publier</button>
                </form>
            </div>
            
            <?php
            $discussions = $pdo->query("
                SELECT d.*, u.nom 
                FROM discussions d 
                JOIN users u ON u.id = d.user_id 
                ORDER BY d.date_msg DESC
            ");
            
            while ($d = $discussions->fetch()):
            ?>
            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="testimonial-author">
                        <div class="user-avatar"><?= strtoupper(substr($d['nom'], 0, 1)) ?></div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($d['nom']) ?></div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary);"><?= formatDate($d['date_msg']) ?></div>
                        </div>
                    </div>
                    <button class="like-btn" onclick="likeMessage(<?= $d['id'] ?>)">
                        <i class="fas fa-heart"></i> <?= $d['likes'] ?>
                    </button>
                </div>
                <div style="margin-bottom: 1rem;"><?= nl2br(htmlspecialchars($d['message'])) ?></div>
                <?php if ($d['media']): ?>
                <div style="margin-top: 1rem;">
                    <?php if ($d['type_media'] == 'image'): ?>
                    <img src="<?= UPLOAD_DIR . htmlspecialchars($d['media']) ?>" style="max-width: 100%; border-radius: var(--radius-sm);">
                    <?php else: ?>
                    <video src="<?= UPLOAD_DIR . htmlspecialchars($d['media']) ?>" controls style="max-width: 100%;"></video>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        
        <!-- PAGE NOTIFICATIONS -->
        <?php elseif ($page == 'notifications' && isset($_SESSION['user'])): ?>
            <h2>Notifications</h2>
            <?php
            $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY date_notif DESC");
            $notifications->execute([$_SESSION['user']['id']]);
            
            if ($notifications->rowCount() == 0): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p>Aucune notification</p>
            </div>
            <?php else:
            while ($n = $notifications->fetch()): ?>
            <div class="card" style="<?= !$n['lu'] ? 'border-left: 3px solid var(--accent-color);' : '' ?>">
                <div style="display: flex; justify-content: space-between;">
                    <div style="font-weight: 600;"><?= htmlspecialchars($n['message']) ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= formatDate($n['date_notif']) ?></div>
                </div>
                <?php if (!$n['lu']): ?>
                <div style="margin-top: 0.5rem;">
                    <a href="?page=notifications&action=read_notification&id=<?= $n['id'] ?>" class="btn btn-sm">Marquer comme lu</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile;
            endif; ?>
        
        <?php else: ?>
            <?php header('Location: ?page=login'); exit; ?>
        <?php endif; ?>
    </main>

    <!-- PIED DE PAGE -->
    <?php if ($page != 'login' && $page != 'register'): ?>
    <footer class="footer">
        <div style="text-align: center;">
            <p style="font-size: 0.875rem;">&copy; <?= date('Y') ?> W-ASSIST</p>
        </div>
    </footer>
    <?php endif; ?>
</div>

<script>
function likeTestimonial(id) {
    fetch(`?action=like_temoignage&id=${id}`)
        .then(response => response.json())
        .then(data => { if (data.success) location.reload(); });
}

function likeMessage(id) {
    fetch(`?action=like_message&id=${id}`)
        .then(response => response.json())
        .then(data => { if (data.success) location.reload(); });
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => alert.remove());
}, 5000);
</script>
</body>
</html>s

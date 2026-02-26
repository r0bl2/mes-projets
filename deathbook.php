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
            // Vérifier si l'email existe déjà
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['error'] = 'Cet email est déjà utilisé.';
            } else {
                // Créer l'utilisateur
                $stmt = $pdo->prepare("INSERT INTO users(nom, email) VALUES(?, ?)");
                $stmt->execute([$nom, $email]);
                
                $_SESSION['success'] = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
                header('Location: ?page=login');
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Une erreur est survenue lors de la création du compte.';
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
        // Mettre à jour la dernière connexion
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
            createNotification($pdo, $_SESSION['user']['id'], 'Votre demande d\'aide a été enregistrée. Un spécialiste vous contactera bientôt.', 'aide');
            $_SESSION['success'] = 'Demande envoyée avec succès ! Un spécialiste vous contactera rapidement.';
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ===========================================
         SECTION 9 : STYLES CSS
    =========================================== -->
    <style>
        /* ===========================================
           9.1 : VARIABLES CSS
        =========================================== */
        :root {
            --primary-blue: #1a237e;
            --secondary-blue: #283593;
            --accent-blue: #3949ab;
            --light-blue: #e8eaf6;
            --white: #ffffff;
            --black: #212121;
            --chocolate: #3e2723;
            --dark-chocolate: #1b1918;
            --success: #2e7d32;
            --warning: #f57c00;
            --danger: #c62828;
            --gray-100: #f5f5f5;
            --gray-200: #eeeeee;
            --gray-300: #e0e0e0;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.15);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.2);
            --radius-sm: 8px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;
        }
        
        /* ===========================================
           9.2 : RESET ET BASES
        =========================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
            color: var(--black);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        /* ===========================================
           9.3 : LAYOUT PRINCIPAL
        =========================================== */
        .app-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(90deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
            color: var(--white);
            padding: 1.5rem 2rem;
            box-shadow: var(--shadow-md);
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
            background: var(--dark-chocolate);
            color: var(--white);
            padding: 2rem;
            text-align: center;
            margin-top: auto;
        }
        
        /* ===========================================
           9.4 : LOGO ET IDENTITÉ VISUELLE
        =========================================== */
        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-icon {
            font-size: 2.5rem;
            color: var(--white);
        }
        
        .logo-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: 1px;
        }
        
        .logo-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 300;
        }
        
        /* ===========================================
           9.5 : TYPOGRAPHIE
        =========================================== */
        h1 {
            font-size: 2.5rem;
            color: var(--primary-blue);
            margin-bottom: 1.5rem;
            font-weight: 700;
        }
        
        h2 {
            font-size: 2rem;
            color: var(--secondary-blue);
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        h3 {
            font-size: 1.5rem;
            color: var(--chocolate);
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-blue);
        }
        
        /* ===========================================
           9.6 : COMPOSANTS CARTES
        =========================================== */
        .card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
        }
        
        .card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .card-title {
            font-size: 1.25rem;
            color: var(--primary-blue);
            font-weight: 600;
        }
        
        /* ===========================================
           9.7 : SYSTÈME DE GRILLE
        =========================================== */
        .grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .grid-2 {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        
        .grid-3 {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }
        
        /* ===========================================
           9.8 : BOUTONS
        =========================================== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--primary-blue) 0%, var(--accent-blue) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--white);
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }
        
        .btn-secondary:hover {
            background: var(--primary-blue);
            color: var(--white);
        }
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        /* ===========================================
           9.9 : FORMULAIRES
        =========================================== */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--chocolate);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(57, 73, 171, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        /* ===========================================
           9.10 : ALERTES ET MESSAGES
        =========================================== */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: rgba(46, 125, 50, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: rgba(198, 40, 40, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        /* ===========================================
           9.11 : NAVIGATION
        =========================================== */
        .nav-menu {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .nav-link {
            color: var(--white);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--accent-blue);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        /* ===========================================
           9.12 : TABLEAU DE BORD
        =========================================== */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            color: var(--accent-blue);
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--chocolate);
            font-size: 0.9rem;
        }
        
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        /* ===========================================
           9.13 : TÉMOIGNAGES
        =========================================== */
        .testimonial-card {
            position: relative;
            padding: 1.5rem;
            background: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--accent-blue);
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
        
        .testimonial-content {
            margin-bottom: 1rem;
            line-height: 1.7;
        }
        
        .like-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: none;
            border: none;
            color: var(--chocolate);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .like-btn:hover {
            color: var(--danger);
        }
        
        /* ===========================================
           9.14 : DEMANDES D'AIDE
        =========================================== */
        .help-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border-top: 4px solid;
            transition: all 0.3s ease;
        }
        
        .help-card.urgence-haute {
            border-top-color: var(--danger);
        }
        
        .help-card.urgence-moyenne {
            border-top-color: var(--warning);
        }
        
        .help-card.urgence-basse {
            border-top-color: var(--success);
        }
        
        .urgence-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* ===========================================
           9.15 : ARTICLES
        =========================================== */
        .article-card {
            background: var(--white);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }
        
        .article-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .article-image {
            height: 200px;
            width: 100%;
            object-fit: cover;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--chocolate);
        }
        
        .article-content {
            padding: 1.5rem;
        }
        
        /* ===========================================
           9.16 : DISCUSSION
        =========================================== */
        .message-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--accent-blue);
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        /* ===========================================
           9.17 : RESPONSIVE DESIGN
        =========================================== */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-menu {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .main-content {
                padding: 0 1rem;
            }
            
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="app-container">
    <!-- ===========================================
         SECTION 10 : EN-TÊTE
    =========================================== -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">🌸</div>
                <div>
                    <div class="logo-text"><?= SITE_NAME ?></div>
                    <div class="logo-subtitle">Women Assist - Plateforme d'entraide</div>
                </div>
            </div>
            
            <!-- ===========================================
                 SECTION 11 : NAVIGATION
            =========================================== -->
            <nav class="nav-menu">
                <?php if (isset($_SESSION['user'])): ?>
                    <!-- Menu utilisateur connecté -->
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
                    // Compter les notifications non lues
                    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = FALSE");
                    $notifStmt->execute([$_SESSION['user']['id']]);
                    $notifCount = $notifStmt->fetchColumn();
                    ?>
                    
                    <div class="user-menu">
                        <a href="?page=notifications" class="nav-link notification-badge">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifCount > 0): ?>
                            <span class="notification-count"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="user-avatar">
                            <?= strtoupper(substr($_SESSION['user']['nom'], 0, 1)) ?>
                        </div>
                        <span><?= htmlspecialchars($_SESSION['user']['nom']) ?></span>
                        <a href="?page=logout" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Menu visiteur non connecté -->
                    <a href="?page=temoignages" class="nav-link <?= $page == 'temoignages' ? 'active' : '' ?>" onclick="return checkLogin('temoignages')">
                        <i class="fas fa-comments"></i> Témoignages
                    </a>
                    <a href="?page=aide" class="nav-link <?= $page == 'aide' ? 'active' : '' ?>" onclick="return checkLogin('aide')">
                        <i class="fas fa-hands-helping"></i> Demande d'aide
                    </a>
                    <a href="?page=articles" class="nav-link <?= $page == 'articles' ? 'active' : '' ?>" onclick="return checkLogin('articles')">
                        <i class="fas fa-shopping-bag"></i> Articles
                    </a>
                    <a href="?page=discussion" class="nav-link <?= $page == 'discussion' ? 'active' : '' ?>" onclick="return checkLogin('discussion')">
                        <i class="fas fa-comment-dots"></i> Discussion
                    </a>
                    <a href="?page=login" class="nav-link <?= $page == 'login' ? 'active' : '' ?> btn btn-primary" style="padding: 0.5rem 1.5rem;">
                        <i class="fas fa-sign-in-alt"></i> Se connecter
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- ===========================================
         SECTION 12 : CONTENU PRINCIPAL
    =========================================== -->
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

        <!-- ===========================================
             SECTION 13 : PAGES DE L'APPLICATION
        =========================================== -->
        
        <!-- 13.1 : PAGE DE CONNEXION -->
        <?php if ($page == 'login'): ?>
            <div class="card p-4" style="max-width: 500px; margin: 4rem auto;">
                <h2 class="text-center mb-3" style="color: var(--primary-blue);">Connexion à W-ASSIST</h2>
                <p class="text-center mb-4" style="color: var(--chocolate);">Plateforme d'entraide dédiée aux femmes</p>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Adresse Email</label>
                        <input type="email" name="email" class="form-control" placeholder="votre@email.com" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary w-100">
                        <i class="fas fa-sign-in-alt"></i> Se connecter
                    </button>
                </form>
                <div class="text-center mt-3">
                    <p>Pas encore de compte ? <a href="?page=register">S'inscrire</a></p>
                </div>
            </div>
        
        <!-- 13.2 : PAGE D'INSCRIPTION -->
        <?php elseif ($page == 'register'): ?>
            <div class="card p-4" style="max-width: 500px; margin: 4rem auto;">
                <h2 class="text-center mb-3">Créer un compte</h2>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Nom complet</label>
                        <input type="text" name="nom" class="form-control" placeholder="Votre nom et prénom" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse Email</label>
                        <input type="email" name="email" class="form-control" placeholder="votre@email.com" required>
                    </div>
                    <button type="submit" name="register" class="btn btn-primary w-100">
                        <i class="fas fa-user-plus"></i> S'inscrire
                    </button>
                </form>
                <div class="text-center mt-3">
                    <p>Déjà un compte ? <a href="?page=login">Se connecter</a></p>
                </div>
            </div>
            
        
        <!-- 13.3 : DASHBOARD -->
        <?php elseif ($page == 'dashboard'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            
            // Récupérer les statistiques
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

            $messagesCount = $pdo->prepare("SELECT COUNT(*) FROM discussions WHERE user_id = ?");
            $messagesCount->execute([$userId]);
            $messagesCount = $messagesCount->fetchColumn();
            ?>
            
            <div class="welcome-message mb-4">
                <h1>Bonjour, <?= htmlspecialchars($_SESSION['user']['nom']) ?> ! 👋</h1>
                <p>Bienvenue sur votre tableau de bord. Voici un aperçu de vos activités.</p>
            </div>
            
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-icon">🗣️</div>
                    <div class="stat-number"><?= $testimonialsCount ?></div>
                    <div class="stat-label">Témoignages</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🤝</div>
                    <div class="stat-number"><?= $helpRequestsCount ?></div>
                    <div class="stat-label">Demandes d'aide</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🛍️</div>
                    <div class="stat-number"><?= $articlesCount ?></div>
                    <div class="stat-label">Articles</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💬</div>
                    <div class="stat-number"><?= $messagesCount ?></div>
                    <div class="stat-label">Messages</div>
                </div>
            </div>
            
            <div class="quick-actions mb-4">
                <a href="?page=temoignages" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nouveau témoignage
                </a>
                <a href="?page=aide" class="btn btn-success">
                    <i class="fas fa-hands-helping"></i> Demander de l'aide
                </a>
                <a href="?page=articles" class="btn btn-secondary">
                    <i class="fas fa-shopping-bag"></i> Vendre un article
                </a>
                <a href="?page=discussion" class="btn btn-warning">
                    <i class="fas fa-comment"></i> Participer à la discussion
                </a>
            </div>
            
            <div class="grid grid-2">
                <!-- Derniers témoignages -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Derniers témoignages</h3>
                        <a href="?page=temoignages" class="btn btn-sm btn-secondary">Voir tout</a>
                    </div>
                    <?php
                    $testimonials = $pdo->query("SELECT t.*, u.nom FROM temoignages t JOIN users u ON u.id = t.user_id ORDER BY t.date_pub DESC LIMIT 3");
                    while ($t = $testimonials->fetch()):
                    ?>
                    <div class="testimonial-card mb-2">
                        <div class="testimonial-header">
                            <div class="testimonial-author">
                                <div class="user-avatar" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                    <?= strtoupper(substr($t['nom'], 0, 1)) ?>
                                </div>
                                <span><?= htmlspecialchars($t['nom']) ?></span>
                            </div>
                            <div class="testimonial-date"><?= formatDate($t['date_pub']) ?></div>
                        </div>
                        <div class="testimonial-content">
                            <?= htmlspecialchars(substr($t['message'], 0, 100)) ?>...
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                
                <!-- Derniers articles -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Articles récents</h3>
                        <a href="?page=articles" class="btn btn-sm btn-secondary">Voir tout</a>
                    </div>
                    <?php
                    $articles = $pdo->query("SELECT a.*, u.nom FROM articles a JOIN users u ON u.id = a.user_id WHERE a.vendu = FALSE ORDER BY a.date_ajout DESC LIMIT 3");
                    while ($a = $articles->fetch()):
                    ?>
                    <div class="article-card mb-2" style="display: flex; align-items: center; padding: 1rem;">
                        <?php if ($a['image']): ?>
                        <div style="width: 60px; height: 60px; margin-right: 1rem; border-radius: var(--radius-sm); overflow: hidden;">
                            <img src="<?= UPLOAD_DIR . htmlspecialchars($a['image']) ?>" alt="<?= htmlspecialchars($a['titre']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <?php endif; ?>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: var(--primary-blue);"><?= htmlspecialchars($a['titre']) ?></div>
                            <div style="color: var(--chocolate); font-size: 0.9rem;"><?= htmlspecialchars(substr($a['description'], 0, 50)) ?>...</div>
                            <div style="font-weight: 700; color: var(--accent-blue); margin-top: 0.25rem;"><?= number_format($a['prix'], 0, ',', ' ') ?> FCFA</div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        
        <!-- 13.4 : TÉMOIGNAGES -->
        <?php elseif ($page == 'temoignages'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            ?>
            
            <div class="section-title">
                <i class="fas fa-comments"></i>
                <h2>Témoignages</h2>
            </div>
            
            <div class="card mb-4">
                <h3 class="mb-3">Partagez votre expérience</h3>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Catégorie</label>
                        <select name="categorie" class="form-control form-select">
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
                        <textarea name="message" class="form-control" placeholder="Partagez votre histoire, vos expériences, vos conseils..." required></textarea>
                    </div>
                    <button type="submit" name="add_temoignage" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Publier le témoignage
                    </button>
                </form>
            </div>
            
            <h3 class="mb-3">Témoignages de la communauté</h3>
            <div class="grid">
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
                            <div class="user-avatar">
                                <?= strtoupper(substr($t['nom'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($t['nom']) ?></div>
                                <?php if ($t['categorie']): ?>
                                <div style="font-size: 0.75rem; color: var(--accent-blue);">#<?= htmlspecialchars($t['categorie']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="testimonial-date"><?= formatDate($t['date_pub']) ?></div>
                    </div>
                    <div class="testimonial-content">
                        <?= nl2br(htmlspecialchars($t['message'])) ?>
                    </div>
                    <div class="testimonial-actions">
                        <button class="like-btn" onclick="likeTestimonial(<?= $t['id'] ?>)">
                            <i class="fas fa-heart"></i>
                            <span class="like-count"><?= $t['likes'] ?></span>
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour au dashboard
                </a>
            </div>
        
        <!-- 13.5 : DEMANDE D'AIDE -->
        <?php elseif ($page == 'aide'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            ?>
            
            <div class="section-title">
                <i class="fas fa-hands-helping"></i>
                <h2>Demande d'aide</h2>
            </div>
            
            <div class="card mb-4">
                <h3 class="mb-3">Formulaire de demande d'assistance</h3>
                <p class="mb-3">Nous sommes là pour vous aider. Remplissez ce formulaire et un spécialiste vous contactera rapidement.</p>
                
                <form method="post">
                    <div class="form-group">
                        <label class="form-label">Situation</label>
                        <select name="situation" class="form-control form-select" required>
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
                        <select name="urgence" class="form-control form-select" required>
                            <option value="basse">Basse (peut attendre quelques jours)</option>
                            <option value="moyenne" selected>Moyenne (à traiter dans les 24h)</option>
                            <option value="haute">Haute (nécessite une intervention immédiate)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Comment vous contacter ?</label>
                        <input type="text" name="contact" class="form-control" placeholder="Numéro de téléphone ou email" required>
                        <small style="color: var(--chocolate); opacity: 0.7;">Ces informations resteront confidentielles</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Décrivez votre situation</label>
                        <textarea name="circonstances" class="form-control" placeholder="Décrivez en détail ce qui se passe, depuis quand, et le type d'aide dont vous avez besoin..." rows="5" required></textarea>
                    </div>
                    
                    <button type="submit" name="add_aide" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Envoyer la demande
                    </button>
                </form>
            </div>
            
            <h3 class="mb-3">Vos demandes précédentes</h3>
            <div class="grid">
                <?php
                $demandes = $pdo->prepare("
                    SELECT * FROM demandes_aide 
                    WHERE user_id = ? 
                    ORDER BY date_demande DESC
                ");
                $demandes->execute([$_SESSION['user']['id']]);
                
                while ($d = $demandes->fetch()):
                    $urgenceClass = 'urgence-' . $d['urgence'];
                ?>
                <div class="help-card <?= $urgenceClass ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h4 style="margin: 0; color: var(--primary-blue);"><?= htmlspecialchars($d['situation']) ?></h4>
                        <span class="urgence-badge"><?= $d['urgence'] ?></span>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <div style="font-weight: 600; color: var(--chocolate); margin-bottom: 0.5rem;">Contact :</div>
                        <div><?= htmlspecialchars($d['contact']) ?></div>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <div style="font-weight: 600; color: var(--chocolate); margin-bottom: 0.5rem;">Description :</div>
                        <div><?= nl2br(htmlspecialchars($d['circonstances'])) ?></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem; color: var(--chocolate); opacity: 0.7;">
                        <div>Statut : <span style="font-weight: 600;"><?= $d['status'] ?></span></div>
                        <div><?= formatDate($d['date_demande']) ?></div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour au dashboard
                </a>
            </div>
        
        <!-- 13.6 : ARTICLES -->
        <?php elseif ($page == 'articles'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            ?>
            
            <div class="section-title">
                <i class="fas fa-shopping-bag"></i>
                <h2>Marché d'articles</h2>
            </div>
            
            <div class="card mb-4">
                <h3 class="mb-3">Vendre un article</h3>
                <form method="post" enctype="multipart/form-data">
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Titre de l'article</label>
                            <input type="text" name="titre" class="form-control" placeholder="Ex: Robe de soirée taille M" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Prix (FCFA)</label>
                            <input type="number" name="prix" class="form-control" placeholder="Ex: 15000" min="0" step="100" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie</label>
                        <select name="categorie" class="form-control form-select">
                            <option value="">Choisir une catégorie</option>
                            <option value="vetements">Vêtements</option>
                            <option value="chaussures">Chaussures</option>
                            <option value="accessoires">Accessoires</option>
                            <option value="cosmetiques">Cosmétiques</option>
                            <option value="livres">Livres</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" placeholder="Décrivez votre article en détail (état, taille, couleur, particularités...)" rows="4" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Image de l'article</label>
                        <div class="file-upload">
                            <input type="file" name="image" id="image" accept="image/*">
                            <label for="image" class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Cliquez pour télécharger une image</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mot secret pour confirmer</label>
                        <input type="text" name="secret" class="form-control" placeholder="Entrez le mot secret" required>
                        <small style="color: var(--chocolate); opacity: 0.7;">Ce mot permet de vérifier que vous êtes bien un membre autorisé</small>
                    </div>
                    
                    <button type="submit" name="add_article" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter l'article
                    </button>
                </form>
            </div>
            
            <h3 class="mb-3">Articles disponibles</h3>
            <div class="grid grid-3">
                <?php
                $articles = $pdo->query("
                    SELECT a.*, u.nom 
                    FROM articles a 
                    JOIN users u ON u.id = a.user_id 
                    WHERE a.vendu = FALSE 
                    ORDER BY a.date_ajout DESC
                ");
                
                while ($a = $articles->fetch()):
                ?>
                <div class="article-card">
                    <?php if ($a['image']): ?>
                    <div class="article-image">
                        <img src="<?= UPLOAD_DIR . htmlspecialchars($a['image']) ?>" alt="<?= htmlspecialchars($a['titre']) ?>">
                    </div>
                    <?php else: ?>
                    <div class="article-image">
                        <i class="fas fa-image" style="font-size: 3rem; color: var(--gray-300);"></i>
                    </div>
                    <?php endif; ?>
                    
                    <div class="article-content">
                        <h4 style="color: var(--primary-blue); margin-bottom: 0.5rem;"><?= htmlspecialchars($a['titre']) ?></h4>
                        
                        <?php if ($a['categorie']): ?>
                        <div style="font-size: 0.75rem; color: var(--accent-blue); margin-bottom: 0.5rem;">
                            #<?= htmlspecialchars($a['categorie']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <div style="color: var(--chocolate); margin-bottom: 1rem; font-size: 0.9rem;">
                            <?= htmlspecialchars(substr($a['description'], 0, 100)) ?>...
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="article-price"><?= number_format($a['prix'], 0, ',', ' ') ?> FCFA</div>
                            <div style="font-size: 0.75rem; color: var(--chocolate); opacity: 0.7;">
                                Par <?= htmlspecialchars($a['nom']) ?>
                            </div>
                        </div>
                        
                        <?php if ($a['user_id'] == $_SESSION['user']['id']): ?>
                        <div class="text-center mt-2">
                            <a href="?page=articles&action=mark_sold&id=<?= $a['id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-check"></i> Marquer comme vendu
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour au dashboard
                </a>
            </div>
        
        <!-- 13.7 : DISCUSSION -->
        <?php elseif ($page == 'discussion'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            ?>
            
            <div class="section-title">
                <i class="fas fa-comment-dots"></i>
                <h2>Espace de discussion</h2>
            </div>
            
            <div class="card mb-4">
                <h3 class="mb-3">Nouveau message</h3>
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label">Votre message</label>
                        <textarea name="message" class="form-control" placeholder="Partagez vos pensées, posez des questions, échangez avec la communauté..." rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Média (optionnel)</label>
                        <div class="file-upload">
                            <input type="file" name="media" id="media" accept="image/*,video/*">
                            <label for="media" class="file-upload-label">
                                <i class="fas fa-camera"></i>
                                <span>Ajouter une photo ou une vidéo</span>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_discussion" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Publier le message
                    </button>
                </form>
            </div>
            
            <h3 class="mb-3">Messages récents</h3>
            <div class="grid">
                <?php
                $discussions = $pdo->query("
                    SELECT d.*, u.nom AS user_name 
                    FROM discussions d 
                    LEFT JOIN users u ON u.id = d.user_id 
                    ORDER BY d.date_msg DESC
                ");
                
                while ($d = $discussions->fetch()):
                ?>
                <div class="message-card">
                    <div class="message-header">
                        <div class="message-author">
                            <div class="user-avatar">
                                <?= strtoupper(substr($d['user_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($d['user_name']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--chocolate); opacity: 0.7;">
                                    <?= formatDate($d['date_msg']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="message-actions">
                            <button class="like-btn" onclick="likeMessage(<?= $d['id'] ?>)">
                                <i class="fas fa-heart"></i>
                                <span class="like-count"><?= $d['likes'] ?></span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="message-content">
                        <?= nl2br(htmlspecialchars($d['message'])) ?>
                    </div>
                    
                    <?php if ($d['media']): ?>
                    <div class="message-media">
                        <?php if ($d['type_media'] == 'image'): ?>
                        <img src="<?= UPLOAD_DIR . htmlspecialchars($d['media']) ?>" alt="Image partagée">
                        <?php elseif ($d['type_media'] == 'video'): ?>
                        <video src="<?= UPLOAD_DIR . htmlspecialchars($d['media']) ?>" controls></video>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour au dashboard
                </a>
            </div>
        
        <!-- 13.8 : NOTIFICATIONS -->
        <?php elseif ($page == 'notifications'):
            // Vérifier la connexion
            if (!isset($_SESSION['user'])) {
                $_SESSION['error'] = 'Veuillez vous connecter pour accéder à cette page.';
                header('Location: ?page=login');
                exit;
            }
            ?>
            
            <div class="section-title">
                <i class="fas fa-bell"></i>
                <h2>Vos notifications</h2>
            </div>
            
            <div class="grid">
                <?php
                $notifications = $pdo->prepare("
                    SELECT * FROM notifications 
                    WHERE user_id = ? 
                    ORDER BY date_notif DESC
                ");
                $notifications->execute([$_SESSION['user']['id']]);
                
                if ($notifications->rowCount() == 0): ?>
                <div class="card text-center p-4">
                    <i class="fas fa-bell-slash" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--chocolate);">Aucune notification</h3>
                    <p>Vous n'avez aucune notification pour le moment.</p>
                </div>
                <?php else:
                while ($n = $notifications->fetch()):
                    $typeClass = $n['type'] ?? 'info';
                ?>
                <div class="notification-card <?= $typeClass ?> <?= !$n['lu'] ? 'unread' : '' ?>">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div style="font-weight: 600; color: var(--primary-blue);">
                            <?php
                            switch ($n['type']) {
                                case 'message': echo 'Nouveau message'; break;
                                case 'aide': echo 'Mise à jour aide'; break;
                                case 'welcome': echo 'Bienvenue'; break;
                                default: echo 'Notification';
                            }
                            ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--chocolate); opacity: 0.7;">
                            <?= formatDate($n['date_notif']) ?>
                        </div>
                    </div>
                    <div><?= htmlspecialchars($n['message']) ?></div>
                    <?php if (!$n['lu']): ?>
                    <div class="text-right mt-2">
                        <a href="?page=notifications&action=read_notification&id=<?= $n['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-check"></i> Marquer comme lu
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile;
                endif; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour au dashboard
                </a>
            </div>
        
        <?php endif; ?>
    </main>

    <!-- ===========================================
         SECTION 14 : PIED DE PAGE
    =========================================== -->
    <?php if ($page != 'login' && $page != 'register'): ?>
    <footer class="footer">
        <div style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 2rem; margin-bottom: 2rem;">
                <div style="flex: 1; min-width: 250px;">
                    <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: var(--white);">🌸 W-ASSIST</div>
                    <p style="opacity: 0.8;">Plateforme d'entraide et de soutien dédiée aux femmes. Ensemble, nous sommes plus fortes.</p>
                </div>
                
                <div style="flex: 1; min-width: 250px;">
                    <h4 style="color: var(--white); margin-bottom: 1rem;">Liens rapides</h4>
                    <ul style="list-style: none;">
                        <li style="margin-bottom: 0.5rem;"><a href="?page=temoignages" style="color: var(--white); opacity: 0.8; text-decoration: none;">Témoignages</a></li>
                        <li style="margin-bottom: 0.5rem;"><a href="?page=aide" style="color: var(--white); opacity: 0.8; text-decoration: none;">Demande d'aide</a></li>
                        <li style="margin-bottom: 0.5rem;"><a href="?page=articles" style="color: var(--white); opacity: 0.8; text-decoration: none;">Articles</a></li>
                        <li style="margin-bottom: 0.5rem;"><a href="?page=discussion" style="color: var(--white); opacity: 0.8; text-decoration: none;">Discussion</a></li>
                    </ul>
                </div>
                
                <div style="flex: 1; min-width: 250px;">
                    <h4 style="color: var(--white); margin-bottom: 1rem;">Contact</h4>
                    <p style="opacity: 0.8;">
                        <i class="fas fa-envelope"></i> contact@w-assist.org<br>
                        <i class="fas fa-phone"></i> +225 27 22 44 55 66
                    </p>
                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                        <a href="#" style="color: var(--white); opacity: 0.8; font-size: 1.25rem;"><i class="fab fa-facebook"></i></a>
                        <a href="#" style="color: var(--white); opacity: 0.8; font-size: 1.25rem;"><i class="fab fa-twitter"></i></a>
                        <a href="#" style="color: var(--white); opacity: 0.8; font-size: 1.25rem;"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            
            <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1.5rem; text-align: center; opacity: 0.7;">
                <p>&copy; <?= date('Y') ?> W-ASSIST. Tous droits réservés. Plateforme créée avec ❤️ pour soutenir les femmes.</p>
                <p style="font-size: 0.875rem; margin-top: 0.5rem;">En cas d'urgence, contactez le 119 (violences conjugales) ou le 111 (aide sociale).</p>
            </div>
        </div>
    </footer>
    <?php endif; ?>
</div>

<!-- ===========================================
     SECTION 15 : JAVASCRIPT
=========================================== -->
<script>
// 15.1 : Vérification de connexion
function checkLogin(page) {
    <?php if (!isset($_SESSION['user'])): ?>
        alert('Veuillez vous connecter pour accéder à cette fonctionnalité.');
        window.location.href = '?page=login';
        return false;
    <?php else: ?>
        return true;
    <?php endif; ?>
}

// 15.2 : Fonctions AJAX pour les likes
function likeTestimonial(id) {
    fetch(`?action=like_temoignage&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
}

function likeMessage(id) {
    fetch(`?action=like_message&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
}

// 15.3 : Animation au chargement
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card, .stat-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('fade-in');
    });
});

// 15.4 : Gestion de l'upload de fichier
document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', function(e) {
        const label = this.nextElementSibling;
        if (this.files.length > 0) {
            label.innerHTML = `<i class="fas fa-check"></i> ${this.files[0].name}`;
        } else {
            label.innerHTML = `<i class="fas fa-cloud-upload-alt"></i> <span>Cliquez pour télécharger</span>`;
        }
    });
});

// 15.5 : Confirmation pour les actions importantes
document.querySelectorAll('a[href*="mark_sold"], button[name="add_aide"]').forEach(element => {
    element.addEventListener('click', function(e) {
        if (!confirm('Êtes-vous sûr de vouloir effectuer cette action ?')) {
            e.preventDefault();
        }
    });
});

// 15.6 : Auto-hide alerts
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s ease';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>
</body>
</html>
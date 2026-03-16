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
define('ADMIN_PASSWORD', password_hash('admin123', PASSWORD_DEFAULT));

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
   SECTION 4 : CRÉATION DES TABLES CORRIGÉE
=========================================== */
$pdo->exec("
-- Table users avec index
CREATE TABLE IF NOT EXISTS users(
    id SERIAL PRIMARY KEY,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255),
    google_id VARCHAR(255),
    telephone VARCHAR(20),
    role VARCHAR(20) DEFAULT 'user',
    avatar VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id);

-- Table temoignages
CREATE TABLE IF NOT EXISTS temoignages(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    categorie VARCHAR(50),
    likes INT DEFAULT 0,
    date_pub TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved BOOLEAN DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_temoignages_user ON temoignages(user_id);
CREATE INDEX IF NOT EXISTS idx_temoignages_date ON temoignages(date_pub DESC);

-- Table pour les likes
CREATE TABLE IF NOT EXISTS likes(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    content_type VARCHAR(20) NOT NULL,
    content_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, content_type, content_id)
);
CREATE INDEX IF NOT EXISTS idx_likes_user ON likes(user_id);
CREATE INDEX IF NOT EXISTS idx_likes_content ON likes(content_type, content_id);

-- Table demandes_aide
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
CREATE INDEX IF NOT EXISTS idx_demandes_user ON demandes_aide(user_id);
CREATE INDEX IF NOT EXISTS idx_demandes_status ON demandes_aide(status);

-- Table articles
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
CREATE INDEX IF NOT EXISTS idx_articles_user ON articles(user_id);
CREATE INDEX IF NOT EXISTS idx_articles_categorie ON articles(categorie);
CREATE INDEX IF NOT EXISTS idx_articles_prix ON articles(prix);

-- Table discussions principale
CREATE TABLE IF NOT EXISTS discussions(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    media VARCHAR(255),
    type_media VARCHAR(20),
    likes INT DEFAULT 0,
    date_msg TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_discussions_date ON discussions(date_msg DESC);

-- Table des réponses aux discussions
CREATE TABLE IF NOT EXISTS discussion_reponses(
    id SERIAL PRIMARY KEY,
    discussion_id INT REFERENCES discussions(id) ON DELETE CASCADE,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    media VARCHAR(255),
    type_media VARCHAR(20),
    likes INT DEFAULT 0,
    date_reponse TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_reponses_discussion ON discussion_reponses(discussion_id);
CREATE INDEX IF NOT EXISTS idx_reponses_date ON discussion_reponses(date_reponse DESC);

-- Table messages_prives
CREATE TABLE IF NOT EXISTS messages_prives(
    id SERIAL PRIMARY KEY,
    sender_id INT REFERENCES users(id) ON DELETE CASCADE,
    receiver_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    lu BOOLEAN DEFAULT FALSE,
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_messages_users ON messages_prives(sender_id, receiver_id);
CREATE INDEX IF NOT EXISTS idx_messages_lu ON messages_prives(receiver_id, lu);

-- Table notifications
CREATE TABLE IF NOT EXISTS notifications(
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    type VARCHAR(30),
    lu BOOLEAN DEFAULT FALSE,
    date_notif TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, lu);
");

/* ===========================================
   SECTION 5 : FONCTIONS UTILITAIRES
=========================================== */

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function uploadFile($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'webm']) {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        return false;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedMimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm'
    ];
    
    if (!isset($allowedMimes[$ext]) || $allowedMimes[$ext] !== $mimeType) {
        return false;
    }
    
    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file['name']);
    
    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        chmod(UPLOAD_DIR . $filename, 0644);
        return $filename;
    }
    
    return false;
}

function createNotification($pdo, $userId, $message, $type = 'info') {
    $stmt = $pdo->prepare("INSERT INTO notifications(user_id, message, type) VALUES (?, ?, ?)");
    return $stmt->execute([$userId, $message, $type]);
}

function formatDate($date) {
    $now = new DateTime();
    $dateObj = new DateTime($date);
    $interval = $now->diff($dateObj);
    
    if ($interval->d == 0) {
        if ($interval->h == 0) {
            if ($interval->i < 1) return "À l'instant";
            if ($interval->i == 1) return "Il y a 1 minute";
            return "Il y a " . $interval->i . " minutes";
        }
        if ($interval->h == 1) return "Il y a 1 heure";
        return "Il y a " . $interval->h . " heures";
    }
    
    if ($interval->d == 1) return "Hier";
    if ($interval->d < 7) {
        if ($interval->d == 1) return "Il y a 1 jour";
        return "Il y a " . $interval->d . " jours";
    }
    
    return $dateObj->format('d/m/Y H:i');
}

function verifyPassword($pdo, $userId, $password) {
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    return $user && password_verify($password, $user['password']);
}

function hasUserLiked($pdo, $userId, $type, $itemId) {
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND content_type = ? AND content_id = ?");
    $stmt->execute([$userId, $type, $itemId]);
    return $stmt->fetch() !== false;
}

function addLike($pdo, $userId, $type, $itemId) {
    try {
        $pdo->beginTransaction();
        
        if (hasUserLiked($pdo, $userId, $type, $itemId)) {
            $pdo->rollBack();
            return false;
        }
        
        $stmt = $pdo->prepare("INSERT INTO likes(user_id, content_type, content_id) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $type, $itemId]);
        
        $table = ($type === 'temoignage') ? 'temoignages' : 
                 (($type === 'discussion') ? 'discussions' : 'discussion_reponses');
        $stmt = $pdo->prepare("UPDATE $table SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$itemId]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/* ===========================================
   SECTION 6 : ROUTEUR ET TRAITEMENT DES REQUÊTES
=========================================== */
$page = $_GET['page'] ?? 'login';
$action = $_GET['action'] ?? null;
$discussion_id = $_GET['discussion_id'] ?? null;

$csrf_token = generateCSRFToken();

/* ===========================================
   SECTION 7 : TRAITEMENT DES FORMULAIRES
=========================================== */

// 7.1 : Inscription
if (isset($_POST['register'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité. Veuillez réessayer.';
    } else {
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $telephone = trim($_POST['telephone']);
        
        $errors = [];
        
        if (!$nom || !$prenom || !$email || !$password || !$telephone) {
            $errors[] = 'Tous les champs sont obligatoires.';
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email invalide.';
        }
        
        if (strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une majuscule.';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un chiffre.';
        }
        
        if ($password !== $confirm_password) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }
        
        if (!preg_match('/^[0-9]{8,}$/', $telephone)) {
            $errors[] = 'Numéro de téléphone invalide (minimum 8 chiffres).';
        }
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->rowCount() > 0) {
                    $_SESSION['error'] = 'Cet email est déjà utilisé.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users(nom, prenom, email, password, telephone) VALUES(?, ?, ?, ?, ?)");
                    $stmt->execute([$nom, $prenom, $email, $hashedPassword, $telephone]);
                    
                    $_SESSION['success'] = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
                    header('Location: ?page=login');
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Une erreur est survenue.';
            }
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
    }
}

// 7.2 : Connexion
if (isset($_POST['login'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité. Veuillez réessayer.';
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStmt->execute([$user['id']]);
            
            $_SESSION['user'] = $user;
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
            
            createNotification($pdo, $user['id'], 'Bienvenue sur W-ASSIST !', 'welcome');
            header('Location: ?page=dashboard');
            exit;
        } else {
            $_SESSION['error'] = 'Email ou mot de passe incorrect.';
        }
    }
}

// 7.3 : Connexion avec Google
if (isset($_GET['google_login'])) {
    $_SESSION['google_auth'] = true;
    header('Location: ?page=google_callback');
    exit;
}

if ($page == 'google_callback' && isset($_SESSION['google_auth'])) {
    $google_email = 'user' . rand(1000, 9999) . '@gmail.com';
    $google_nom = 'User';
    $google_prenom = 'Google';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR google_id IS NOT NULL");
    $stmt->execute([$google_email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users(nom, prenom, email, google_id) VALUES(?, ?, ?, ?)");
        $stmt->execute([$google_nom, $google_prenom, $google_email, 'google_' . uniqid()]);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$google_email]);
        $user = $stmt->fetch();
    }
    
    $_SESSION['user'] = $user;
    createNotification($pdo, $user['id'], 'Bienvenue sur W-ASSIST (connexion Google) !', 'welcome');
    unset($_SESSION['google_auth']);
    header('Location: ?page=dashboard');
    exit;
}

// 7.4 : Déconnexion
if ($page == 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// 7.5 : Témoignage
if (isset($_POST['add_temoignage']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
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
}

// 7.6 : Like témoignage
if ($action == 'like_temoignage' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $result = addLike($pdo, $_SESSION['user']['id'], 'temoignage', $id);
        echo json_encode(['success' => $result, 'alreadyLiked' => !$result]);
        exit;
    }
}

// 7.7 : Demande aide
if (isset($_POST['add_aide']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
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
}

// 7.8 : Mettre à jour le statut d'une demande d'aide
if ($action == 'update_help_status' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    $status = $_GET['status'] ?? '';
    
    if ($id && in_array($status, ['en_attente', 'traite', 'refuse'])) {
        $stmt = $pdo->prepare("SELECT user_id FROM demandes_aide WHERE id = ?");
        $stmt->execute([$id]);
        $demande = $stmt->fetch();
        
        if ($demande && $demande['user_id'] == $_SESSION['user']['id']) {
            $stmt = $pdo->prepare("UPDATE demandes_aide SET status = ?, date_traitement = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            createNotification($pdo, $_SESSION['user']['id'], "Votre demande d'aide a été " . ($status == 'traite' ? 'traitée' : 'refusée'), 'aide');
            $_SESSION['success'] = 'Statut mis à jour avec succès !';
        }
    }
    header('Location: ?page=aide');
    exit;
}

// 7.9 : Articles
if (isset($_POST['add_article']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
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
}

// 7.10 : Supprimer un article
if ($action == 'delete_article' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT user_id FROM articles WHERE id = ?");
        $stmt->execute([$id]);
        $article = $stmt->fetch();
        
        if ($article && $article['user_id'] == $_SESSION['user']['id']) {
            $_SESSION['delete_article_id'] = $id;
            header('Location: ?page=verify_delete_password');
            exit;
        }
    }
    header('Location: ?page=articles');
    exit;
}

// 7.11 : Vérification du mot de passe pour la suppression
if (isset($_POST['verify_delete_password']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
        $password = $_POST['password'];
        $admin_password = $_POST['admin_password'] ?? '';
        $article_id = $_SESSION['delete_article_id'] ?? 0;
        
        if ($article_id) {
            $userVerified = verifyPassword($pdo, $_SESSION['user']['id'], $password);
            $adminVerified = password_verify($admin_password, ADMIN_PASSWORD);
            
            if ($userVerified && $adminVerified) {
                $stmt = $pdo->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
                $stmt->execute([$article_id, $_SESSION['user']['id']]);
                
                unset($_SESSION['delete_article_id']);
                $_SESSION['success'] = 'Article supprimé avec succès !';
            } else {
                $_SESSION['error'] = 'Mot de passe utilisateur ou mot de passe admin incorrect.';
            }
        }
    }
    header('Location: ?page=articles');
    exit;
}

// 7.12 : Marquer article comme vendu
if ($action == 'mark_sold' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("UPDATE articles SET vendu = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user']['id']]);
        header('Location: ?page=articles');
        exit;
    }
}

// 7.13 : Discussion principale
if (isset($_POST['add_discussion']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
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
}

// 7.14 : Répondre à une discussion
if (isset($_POST['add_reponse']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
        $discussion_id = $_POST['discussion_id'] ?? 0;
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
        
        if ($discussion_id && $message) {
            $stmt = $pdo->prepare("INSERT INTO discussion_reponses(discussion_id, user_id, message, media, type_media) VALUES(?, ?, ?, ?, ?)");
            if ($stmt->execute([$discussion_id, $_SESSION['user']['id'], $message, $media, $type])) {
                createNotification($pdo, $_SESSION['user']['id'], 'Vous avez répondu à une discussion', 'reponse');
                header('Location: ?page=discussion&view=' . $discussion_id);
                exit;
            }
        }
    }
}

// 7.15 : Like message discussion ou réponse
if ($action == 'like_message' && isset($_SESSION['user'])) {
    $id = $_GET['id'] ?? 0;
    $type = $_GET['type'] ?? 'discussion';
    if ($id) {
        $result = addLike($pdo, $_SESSION['user']['id'], $type, $id);
        echo json_encode(['success' => $result, 'alreadyLiked' => !$result]);
        exit;
    }
}

// 7.16 : Message privé
if (isset($_POST['send_message']) && isset($_SESSION['user'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Erreur de sécurité.';
    } else {
        $receiver_id = $_POST['receiver_id'] ?? 0;
        $message = trim($_POST['message']);
        
        if ($receiver_id && $message) {
            $stmt = $pdo->prepare("INSERT INTO messages_prives(sender_id, receiver_id, message) VALUES(?, ?, ?)");
            if ($stmt->execute([$_SESSION['user']['id'], $receiver_id, $message])) {
                createNotification($pdo, $receiver_id, 'Vous avez reçu un nouveau message de ' . $_SESSION['user']['prenom'], 'message');
                $_SESSION['success'] = 'Message envoyé avec succès !';
                header('Location: ?page=messages&user=' . $receiver_id);
                exit;
            }
        }
    }
}

// 7.17 : Marquer notification comme lue
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
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
            border-color: var(--danger);
        }
        
        .btn-google {
            background-color: #fff;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-google:hover {
            background-color: #f8f8f8;
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
            animation: slideIn 0.3s ease;
            box-shadow: var(--shadow-md);
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
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
        
        .alert-info {
            background-color: rgba(9, 132, 227, 0.1);
            color: var(--accent-color);
            border-color: rgba(9, 132, 227, 0.2);
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
            transition: all 0.2s ease;
        }
        
        .like-btn:hover {
            color: var(--danger);
            transform: scale(1.05);
        }
        
        .like-btn.liked {
            color: var(--danger);
            pointer-events: none;
            opacity: 0.7;
        }
        
        .like-btn.liked i {
            animation: heartBeat 0.3s ease;
        }
        
        .like-btn:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        @keyframes heartBeat {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
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
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-en_attente {
            background-color: var(--warning);
            color: #fff;
        }
        
        .status-traite {
            background-color: var(--success);
            color: #fff;
        }
        
        .status-refuse {
            background-color: var(--danger);
            color: #fff;
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
        
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: var(--bg-secondary);
            margin: 15% auto;
            padding: 2rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            max-width: 400px;
        }
        
        .reponse-card {
            background-color: var(--border-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin: 0.5rem 0 0.5rem 2rem;
        }
        
        .reponse-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .reponse-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .discussion-thread {
            border-left: 2px solid var(--border-color);
            padding-left: 1rem;
        }
        
        .show-replies {
            cursor: pointer;
            color: var(--accent-color);
            font-size: 0.875rem;
            margin-top: 0.5rem;
            display: inline-block;
        }
        
        .show-replies:hover {
            text-decoration: underline;
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
    <!-- EN-TÊTE -->
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
                            <?= strtoupper(substr($_SESSION['user']['prenom'] ?? $_SESSION['user']['nom'], 0, 1)) ?>
                        </div>
                        <span style="font-size: 0.875rem;"><?= htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']) ?></span>
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
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mot de passe</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary" style="width: 100%; margin-bottom: 1rem;">Se connecter</button>
                </form>
                
                <div class="divider">ou</div>
                
                <a href="?google_login=1" class="btn btn-google" style="width: 100%;">
                    <i class="fab fa-google"></i> Se connecter avec Google
                </a>
                
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
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="grid grid-2" style="gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Nom</label>
                            <input type="text" name="nom" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom</label>
                            <input type="text" name="prenom" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="tel" name="telephone" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mot de passe (min. 8 caractères, 1 majuscule, 1 chiffre)</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirmer le mot de passe</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="register" class="btn btn-primary" style="width: 100%;">S'inscrire</button>
                </form>
                <div class="divider"></div>
                <p style="text-align: center; font-size: 0.875rem;">
                    Déjà un compte ? <a href="?page=login" style="color: var(--accent-color);">Se connecter</a>
                </p>
            </div>
        
        <!-- PAGE DE VÉRIFICATION DU MOT DE PASSE POUR SUPPRESSION -->
        <?php elseif ($page == 'verify_delete_password' && isset($_SESSION['user']) && isset($_SESSION['delete_article_id'])): ?>
            <div class="card" style="max-width: 400px; margin: 4rem auto;">
                <h2 style="text-align: center;">Confirmation de suppression</h2>
                <p style="text-align: center; margin-bottom: 1.5rem;">Veuillez entrer vos mots de passe pour confirmer la suppression.</p>
                
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="form-group">
                        <label class="form-label">Votre mot de passe</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mot de passe administrateur</label>
                        <input type="password" name="admin_password" class="form-control" required>
                    </div>
                    <button type="submit" name="verify_delete_password" class="btn btn-danger" style="width: 100%;">
                        <i class="fas fa-trash"></i> Confirmer la suppression
                    </button>
                    <a href="?page=articles" class="btn" style="width: 100%; margin-top: 0.5rem;">Annuler</a>
                </form>
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
            
            $pendingHelpCount = $pdo->prepare("SELECT COUNT(*) FROM demandes_aide WHERE user_id = ? AND status = 'en_attente'");
            $pendingHelpCount->execute([$userId]);
            $pendingHelpCount = $pendingHelpCount->fetchColumn();

            $discussionsCount = $pdo->prepare("SELECT COUNT(*) FROM discussions WHERE user_id = ?");
            $discussionsCount->execute([$userId]);
            $discussionsCount = $discussionsCount->fetchColumn();

            $reponsesCount = $pdo->prepare("SELECT COUNT(*) FROM discussion_reponses WHERE user_id = ?");
            $reponsesCount->execute([$userId]);
            $reponsesCount = $reponsesCount->fetchColumn();
            ?>
            
            <div style="margin-bottom: 2rem;">
                <h1>Bonjour, <?= htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']) ?></h1>
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
                    <?php if ($pendingHelpCount > 0): ?>
                    <div style="font-size: 0.7rem; color: var(--warning);"><?= $pendingHelpCount ?> en attente</div>
                    <?php endif; ?>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $articlesCount ?></div>
                    <div class="stat-label">Articles</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $discussionsCount + $reponsesCount ?></div>
                    <div class="stat-label">Messages</div>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="?page=temoignages" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau témoignage</a>
                <a href="?page=aide" class="btn"><i class="fas fa-hands-helping"></i> Demander de l'aide</a>
                <a href="?page=articles" class="btn"><i class="fas fa-shopping-bag"></i> Vendre un article</a>
                <a href="?page=discussion" class="btn"><i class="fas fa-comment-dots"></i> Discussion</a>
            </div>
            
            <!-- Dernières demandes d'aide -->
            <?php
            $recentHelps = $pdo->prepare("SELECT * FROM demandes_aide WHERE user_id = ? ORDER BY date_demande DESC LIMIT 3");
            $recentHelps->execute([$userId]);
            if ($recentHelps->rowCount() > 0):
            ?>
            <div style="margin-top: 2rem;">
                <h3>Dernières demandes d'aide</h3>
                <?php while ($h = $recentHelps->fetch()): ?>
                <div class="help-card urgence-<?= $h['urgence'] ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0;"><?= htmlspecialchars($h['situation']) ?></h4>
                        <span class="status-badge status-<?= $h['status'] ?>"><?= $h['status'] ?></span>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.5rem;">
                        <?= formatDate($h['date_demande']) ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        
        <!-- PAGE TÉMOIGNAGES -->
        <?php elseif ($page == 'temoignages' && isset($_SESSION['user'])): ?>
            <h2>Témoignages</h2>
            
            <div class="card">
                <h3 class="card-title">Partagez votre expérience</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
            
            <h3>Témoignages de la communauté</h3>
            <?php
            $testimonials = $pdo->query("
                SELECT t.*, u.nom, u.prenom 
                FROM temoignages t 
                JOIN users u ON u.id = t.user_id 
                WHERE t.approved = TRUE 
                ORDER BY t.date_pub DESC
            ");
            
            while ($t = $testimonials->fetch()):
                $hasLiked = hasUserLiked($pdo, $_SESSION['user']['id'], 'temoignage', $t['id']);
            ?>
            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="testimonial-author">
                        <div class="user-avatar"><?= strtoupper(substr($t['prenom'] ?? $t['nom'], 0, 1)) ?></div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($t['prenom'] . ' ' . $t['nom']) ?></div>
                            <?php if ($t['categorie']): ?>
                            <div style="font-size: 0.7rem; color: var(--accent-color);">#<?= htmlspecialchars($t['categorie']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= formatDate($t['date_pub']) ?></div>
                </div>
                <div style="margin-bottom: 1rem;"><?= nl2br(htmlspecialchars($t['message'])) ?></div>
                <button class="like-btn <?= $hasLiked ? 'liked' : '' ?>" onclick="likeTestimonial(<?= $t['id'] ?>)" <?= $hasLiked ? 'disabled' : '' ?>>
                    <i class="fas fa-heart"></i> <span class="like-count"><?= $t['likes'] ?></span>
                </button>
            </div>
            <?php endwhile; ?>
        
        <!-- PAGE DEMANDE D'AIDE -->
        <?php elseif ($page == 'aide' && isset($_SESSION['user'])): ?>
            <h2>Demande d'aide</h2>
            
            <div class="card">
                <h3 class="card-title">Formulaire de demande d'assistance</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--text-secondary);">
                    <div>
                        <span class="status-badge status-<?= $d['status'] ?>"><?= $d['status'] ?></span>
                        <?php if ($d['date_traitement']): ?>
                        <span style="margin-left: 0.5rem;">Traité le <?= date('d/m/Y', strtotime($d['date_traitement'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <span><?= formatDate($d['date_demande']) ?></span>
                </div>
                
                <?php if ($d['status'] == 'en_attente'): ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                    <a href="?action=update_help_status&id=<?= $d['id'] ?>&status=traite" class="btn btn-sm btn-success" onclick="return confirm('Marquer cette demande comme traitée ?')">
                        <i class="fas fa-check"></i> Marquer comme traitée
                    </a>
                    <a href="?action=update_help_status&id=<?= $d['id'] ?>&status=refuse" class="btn btn-sm btn-danger" style="margin-left: 0.5rem;" onclick="return confirm('Refuser cette demande ?')">
                        <i class="fas fa-times"></i> Refuser
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        
        <!-- PAGE ARTICLES -->
        <?php elseif ($page == 'articles' && isset($_SESSION['user'])):
            $search = $_GET['search'] ?? '';
            $categorie = $_GET['categorie'] ?? '';
            $prix_range = $_GET['prix'] ?? '';
            $sort = $_GET['sort'] ?? 'recent';
            
            $sql = "SELECT a.*, u.nom, u.prenom FROM articles a JOIN users u ON u.id = a.user_id WHERE a.vendu = FALSE";
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
                                <div style="font-size: 0.7rem; color: var(--text-muted);">Par <?= htmlspecialchars($a['prenom'] . ' ' . $a['nom']) ?></div>
                                
                                <?php if ($a['user_id'] == $_SESSION['user']['id']): ?>
                                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color); display: flex; gap: 0.5rem;">
                                    <a href="?page=articles&action=mark_sold&id=<?= $a['id'] ?>" class="btn btn-sm btn-success" style="flex: 1;" onclick="return confirm('Marquer comme vendu ?')">
                                        <i class="fas fa-check"></i> Vendu
                                    </a>
                                    <a href="?page=articles&action=delete_article&id=<?= $a['id'] ?>" class="btn btn-sm btn-danger" style="flex: 1;" onclick="return confirm('Voulez-vous supprimer cet article ?')">
                                        <i class="fas fa-trash"></i> Supprimer
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
                    
                    <div id="add-product-form" style="display: none; margin-top: 2rem;">
                        <div class="card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h3 style="font-size: 1rem;">Vendre un article</h3>
                                <button onclick="this.parentElement.parentElement.parentElement.style.display = 'none'" style="background: none; border: none; cursor: pointer; font-size: 1.5rem;">&times;</button>
                            </div>
                            
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
        
        <!-- PAGE DISCUSSION AVEC RÉPONSES -->
        <?php elseif ($page == 'discussion' && isset($_SESSION['user'])):
            $view_discussion = $_GET['view'] ?? null;
            ?>
            
            <?php if ($view_discussion): 
                $discussion = $pdo->prepare("
                    SELECT d.*, u.nom, u.prenom 
                    FROM discussions d 
                    JOIN users u ON u.id = d.user_id 
                    WHERE d.id = ?
                ");
                $discussion->execute([$view_discussion]);
                $discussion = $discussion->fetch();
                
                if (!$discussion):
                    header('Location: ?page=discussion');
                    exit;
                endif;
                
                $hasLiked = hasUserLiked($pdo, $_SESSION['user']['id'], 'discussion', $discussion['id']);
                
                $reponses = $pdo->prepare("
                    SELECT r.*, u.nom, u.prenom 
                    FROM discussion_reponses r 
                    JOIN users u ON u.id = r.user_id 
                    WHERE r.discussion_id = ? 
                    ORDER BY r.date_reponse ASC
                ");
                $reponses->execute([$view_discussion]);
                ?>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2>Discussion</h2>
                    <a href="?page=discussion" class="btn btn-sm">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                </div>
                
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <div class="testimonial-author">
                            <div class="user-avatar"><?= strtoupper(substr($discussion['prenom'] ?? $discussion['nom'], 0, 1)) ?></div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($discussion['prenom'] . ' ' . $discussion['nom']) ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-secondary);"><?= formatDate($discussion['date_msg']) ?></div>
                            </div>
                        </div>
                        <button class="like-btn <?= $hasLiked ? 'liked' : '' ?>" onclick="likeMessage(<?= $discussion['id'] ?>, 'discussion')" <?= $hasLiked ? 'disabled' : '' ?>>
                            <i class="fas fa-heart"></i> <span class="like-count"><?= $discussion['likes'] ?></span>
                        </button>
                    </div>
                    <div style="margin-bottom: 1rem;"><?= nl2br(htmlspecialchars($discussion['message'])) ?></div>
                    <?php if ($discussion['media']): ?>
                    <div style="margin-top: 1rem;">
                        <?php if ($discussion['type_media'] == 'image'): ?>
                        <img src="<?= UPLOAD_DIR . htmlspecialchars($discussion['media']) ?>" style="max-width: 100%; border-radius: var(--radius-sm);">
                        <?php else: ?>
                        <video src="<?= UPLOAD_DIR . htmlspecialchars($discussion['media']) ?>" controls style="max-width: 100%;"></video>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="card" style="margin-top: 1rem;">
                    <h3 class="card-title">Répondre</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="discussion_id" value="<?= $view_discussion ?>">
                        <div class="form-group">
                            <textarea name="message" class="form-control" placeholder="Votre réponse..." rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Média (optionnel)</label>
                            <input type="file" name="media" accept="image/*,video/*" class="form-control">
                        </div>
                        <button type="submit" name="add_reponse" class="btn btn-primary">Publier la réponse</button>
                    </form>
                </div>
                
                <h3 style="margin-top: 2rem;">Réponses (<?= $reponses->rowCount() ?>)</h3>
                
                <?php if ($reponses->rowCount() == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-comment-dots"></i>
                    <p>Aucune réponse pour le moment. Soyez le premier à répondre !</p>
                </div>
                <?php else: 
                    while ($r = $reponses->fetch()):
                        $hasLikedReponse = hasUserLiked($pdo, $_SESSION['user']['id'], 'reponse', $r['id']);
                ?>
                <div class="reponse-card">
                    <div class="reponse-header">
                        <div class="reponse-author">
                            <div class="user-avatar" style="width: 24px; height: 24px; font-size: 0.7rem;"><?= strtoupper(substr($r['prenom'] ?? $r['nom'], 0, 1)) ?></div>
                            <div>
                                <span style="font-weight: 600; font-size: 0.875rem;"><?= htmlspecialchars($r['prenom'] . ' ' . $r['nom']) ?></span>
                                <span style="font-size: 0.7rem; color: var(--text-secondary); margin-left: 0.5rem;"><?= formatDate($r['date_reponse']) ?></span>
                            </div>
                        </div>
                        <button class="like-btn <?= $hasLikedReponse ? 'liked' : '' ?>" style="font-size: 0.75rem;" onclick="likeMessage(<?= $r['id'] ?>, 'reponse')" <?= $hasLikedReponse ? 'disabled' : '' ?>>
                            <i class="fas fa-heart"></i> <span class="like-count"><?= $r['likes'] ?></span>
                        </button>
                    </div>
                    <div><?= nl2br(htmlspecialchars($r['message'])) ?></div>
                    <?php if ($r['media']): ?>
                    <div style="margin-top: 0.5rem;">
                        <?php if ($r['type_media'] == 'image'): ?>
                        <img src="<?= UPLOAD_DIR . htmlspecialchars($r['media']) ?>" style="max-width: 200px; border-radius: var(--radius-sm);">
                        <?php else: ?>
                        <video src="<?= UPLOAD_DIR . htmlspecialchars($r['media']) ?>" controls style="max-width: 200px;"></video>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
                <?php endif; ?>
                
            <?php else: 
                ?>
                <h2>Discussion</h2>
                
                <div class="card">
                    <h3 class="card-title">Nouveau message</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
                    SELECT d.*, u.nom, u.prenom,
                           (SELECT COUNT(*) FROM discussion_reponses WHERE discussion_id = d.id) as reponses_count
                    FROM discussions d 
                    JOIN users u ON u.id = d.user_id 
                    ORDER BY d.date_msg DESC
                ");
                
                while ($d = $discussions->fetch()):
                    $hasLiked = hasUserLiked($pdo, $_SESSION['user']['id'], 'discussion', $d['id']);
                ?>
                <div class="testimonial-card">
                    <div class="testimonial-header">
                        <div class="testimonial-author">
                            <div class="user-avatar"><?= strtoupper(substr($d['prenom'] ?? $d['nom'], 0, 1)) ?></div>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-secondary);"><?= formatDate($d['date_msg']) ?></div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <span class="badge" style="background-color: var(--border-light); padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.7rem;">
                                <i class="fas fa-comment"></i> <?= $d['reponses_count'] ?> réponse(s)
                            </span>
                            <button class="like-btn <?= $hasLiked ? 'liked' : '' ?>" onclick="likeMessage(<?= $d['id'] ?>, 'discussion')" <?= $hasLiked ? 'disabled' : '' ?>>
                                <i class="fas fa-heart"></i> <span class="like-count"><?= $d['likes'] ?></span>
                            </button>
                        </div>
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
                    
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                        <a href="?page=discussion&view=<?= $d['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-reply"></i> Voir la discussion (<?= $d['reponses_count'] ?>)
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        
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
                    <a href="?action=read_notification&id=<?= $n['id'] ?>" class="btn btn-sm">Marquer comme lu</a>
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
    <?php if ($page != 'login' && $page != 'register' && $page != 'verify_delete_password'): ?>
    <footer class="footer">
        <div style="text-align: center;">
            <p style="font-size: 0.875rem;">&copy; <?= date('Y') ?> W-ASSIST - Plateforme d'Assistance aux Femmes</p>
        </div>
    </footer>
    <?php endif; ?>
</div>

<script>
let likeInProgress = false;

function likeTestimonial(id) {
    if (likeInProgress) return;
    likeInProgress = true;
    
    const button = event.currentTarget;
    const countSpan = button.querySelector('.like-count');
    const originalCount = parseInt(countSpan.textContent);
    
    fetch(`?action=like_temoignage&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                countSpan.textContent = originalCount + 1;
                button.classList.add('liked');
                button.disabled = true;
                showNotification('Témoignage aimé !', 'success');
            } else if (data.alreadyLiked) {
                showNotification('Vous avez déjà aimé ce témoignage', 'info');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showNotification('Une erreur est survenue', 'error');
        })
        .finally(() => {
            likeInProgress = false;
        });
}

function likeMessage(id, type = 'discussion') {
    if (likeInProgress) return;
    likeInProgress = true;
    
    const button = event.currentTarget;
    const countSpan = button.querySelector('.like-count');
    const originalCount = parseInt(countSpan.textContent);
    
    fetch(`?action=like_message&id=${id}&type=${type}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                countSpan.textContent = originalCount + 1;
                button.classList.add('liked');
                button.disabled = true;
                showNotification('Message aimé !', 'success');
            } else if (data.alreadyLiked) {
                showNotification('Vous avez déjà aimé ce message', 'info');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showNotification('Une erreur est survenue', 'error');
        })
        .finally(() => {
            likeInProgress = false;
        });
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '300px';
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 
                           type === 'error' ? 'exclamation-circle' : 
                           'info-circle'}"></i>
        ${message}
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => alert.remove());
}, 5000);
</script>
</body>
</html>

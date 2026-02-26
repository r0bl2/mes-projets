<?php
session_start();

// Configuration de la base de données PostgreSQL
$host = 'localhost';
$port = '5432';
$dbname = 'quizz';
$user = 'postgres';
$password = 'postgresql';

$pdo = null;

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_connected = true;
} catch(PDOException $e) {
    $db_connected = false;
    error_log("Erreur de connexion BD: " . $e->getMessage());
}

function getLevelRank($percentage) {
    if ($percentage >= 90) return "Maitre Supreme";
    if ($percentage >= 75) return "Alpha";
    if ($percentage >= 60) return "Maitre du Jeu";
    if ($percentage >= 40) return "Amateur";
    return "Debutant";
}

// Traitement des requêtes AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données']);
        exit;
    }
    
    try {
        // =============================================
        // ACTION: LOGIN
        // =============================================
        if ($action === 'login') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['username']) || empty($data['password'])) {
                echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs']);
                exit;
            }
            
            // Vérifier si l'utilisateur existe
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$data['username']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // En mode démo, on accepte sans vérification de mot de passe
            // Dans un vrai projet, utilisez: password_verify($data['password'], $user['password'])
            
            if ($user) {
                // Mettre à jour last_login
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_level'] = $user['current_level'];
                
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'level' => $user['current_level'],
                        'games_played' => (int)$user['games_played'],
                        'total_score' => (int)$user['total_score'],
                        'best_score' => (int)$user['best_score'],
                        'avg_score' => $user['games_played'] > 0 ? round($user['total_score'] / $user['games_played']) : 0
                    ]
                ]);
            } else {
                // Créer un nouvel utilisateur pour la démo
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, current_level, games_played, total_score, best_score, created_at, last_login) VALUES (?, ?, ?, 'Debutant', 0, 0, 0, NOW(), NOW()) RETURNING id");
                $stmt->execute([$data['username'], $data['username'] . '@demo.com', 'demo123']);
                $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $_SESSION['user_id'] = $newUser['id'];
                $_SESSION['username'] = $data['username'];
                $_SESSION['user_level'] = 'Debutant';
                
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $newUser['id'],
                        'username' => $data['username'],
                        'email' => $data['username'] . '@demo.com',
                        'level' => 'Debutant',
                        'games_played' => 0,
                        'total_score' => 0,
                        'best_score' => 0,
                        'avg_score' => 0
                    ]
                ]);
            }
            exit;
        }
        
        // =============================================
        // ACTION: REGISTER
        // =============================================
        if ($action === 'register') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
                echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs']);
                exit;
            }
            
            if (strlen($data['password']) < 6) {
                echo json_encode(['success' => false, 'message' => 'Le mot de passe doit contenir au moins 6 caractères']);
                exit;
            }
            
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Email invalide']);
                exit;
            }
            
            // Vérifier si l'utilisateur existe déjà
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$data['username'], $data['email']]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Nom d\'utilisateur ou email déjà utilisé']);
                exit;
            }
            
            // Créer le nouvel utilisateur
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, current_level, created_at) VALUES (?, ?, ?, 'Debutant', NOW())");
            $stmt->execute([$data['username'], $data['email'], $hashedPassword]);
            
            echo json_encode(['success' => true, 'message' => 'Inscription réussie ! Vous pouvez maintenant vous connecter.']);
            exit;
        }
        
        // =============================================
        // ACTION: GET_QUESTIONS
        // =============================================
        if ($action === 'get_questions') {
            $level = $_GET['level'] ?? 'Debutant';
            
            // Récupérer 20 questions aléatoires du niveau demandé
            $stmt = $pdo->prepare("SELECT id, question, option1, option2, option3, option4, correct_answer FROM questions WHERE level = ? ORDER BY RANDOM() LIMIT 20");
            $stmt->execute([$level]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($questions)) {
                echo json_encode(['success' => false, 'message' => 'Aucune question trouvée pour ce niveau']);
                exit;
            }
            
            // Formater les questions pour le jeu
            $formattedQuestions = [];
            foreach ($questions as $q) {
                $formattedQuestions[] = [
                    'id' => $q['id'],
                    'question' => $q['question'],
                    'options' => [$q['option1'], $q['option2'], $q['option3'], $q['option4']],
                    'correct' => (int)$q['correct_answer'] - 1 // Convertir 1-4 en 0-3
                ];
            }
            
            echo json_encode(['success' => true, 'questions' => $formattedQuestions]);
            exit;
        }
        
        // =============================================
        // ACTION: SAVE_SCORE
        // =============================================
        if ($action === 'save_score') {
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Non authentifié']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $score = $data['score'] ?? 0;
            $total = $data['total'] ?? 20;
            $percentage = $data['percentage'] ?? 0;
            $currentLevel = $data['level'] ?? 'Debutant';
            
            // Mettre à jour les statistiques de l'utilisateur
            $stmt = $pdo->prepare("SELECT games_played, total_score, best_score FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $games_played = $user['games_played'] + 1;
            $total_score = $user['total_score'] + $score;
            $best_score = max($user['best_score'], $percentage);
            
            // Déterminer si le joueur peut passer au niveau supérieur
            $nextLevel = null;
            $newRank = getLevelRank($percentage);
            
            $stmt = $pdo->prepare("SELECT name FROM levels ORDER BY min_score");
            $stmt->execute();
            $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $currentIndex = array_search($currentLevel, $levels);
            
            if ($percentage >= 90 && $currentIndex !== false && $currentIndex < count($levels) - 1) {
                $nextLevel = $levels[$currentIndex + 1];
                
                // Mettre à jour le niveau actuel de l'utilisateur
                $stmt = $pdo->prepare("UPDATE users SET current_level = ? WHERE id = ?");
                $stmt->execute([$nextLevel, $_SESSION['user_id']]);
                $_SESSION['user_level'] = $nextLevel;
            }
            
            // Mettre à jour les statistiques
            $stmt = $pdo->prepare("UPDATE users SET games_played = ?, total_score = ?, best_score = ? WHERE id = ?");
            $stmt->execute([$games_played, $total_score, $best_score, $_SESSION['user_id']]);
            
            // Enregistrer la partie
            $stmt = $pdo->prepare("INSERT INTO game_history (user_id, level, score, total_questions, percentage, played_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $currentLevel, $score, $total, $percentage]);
            
            // Vérifier les succès à débloquer
            checkAchievements($_SESSION['user_id'], $pdo);
            
            echo json_encode([
                'success' => true, 
                'new_level' => $newRank,
                'next_level' => $nextLevel,
                'unlocked' => $nextLevel !== null
            ]);
            exit;
        }
        
        // =============================================
        // ACTION: GET_RANKING
        // =============================================
        if ($action === 'get_ranking') {
            $level = $_GET['level'] ?? 'Debutant';
            
            // Récupérer le classement pour ce niveau
            $stmt = $pdo->prepare("
                SELECT u.username, MAX(g.percentage) as best_score, COUNT(g.id) as games_played
                FROM users u
                LEFT JOIN game_history g ON u.id = g.user_id AND g.level = ?
                GROUP BY u.id, u.username
                HAVING COUNT(g.id) > 0
                ORDER BY best_score DESC, games_played DESC
                LIMIT 20
            ");
            $stmt->execute([$level]);
            $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formater les résultats
            $formattedRanking = [];
            foreach ($ranking as $r) {
                $formattedRanking[] = [
                    'username' => $r['username'],
                    'score' => (int)$r['best_score'],
                    'games' => (int)$r['games_played']
                ];
            }
            
            echo json_encode(['success' => true, 'ranking' => $formattedRanking]);
            exit;
        }
        
        // =============================================
        // ACTION: GET_USER_STATS
        // =============================================
        if ($action === 'get_user_stats' && isset($_SESSION['user_id'])) {
            // Récupérer les stats de l'utilisateur
            $stmt = $pdo->prepare("SELECT username, current_level, games_played, total_score, best_score FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Récupérer les niveaux complétés (score >= 60%)
                $stmt = $pdo->prepare("SELECT DISTINCT level FROM game_history WHERE user_id = ? AND percentage >= 60");
                $stmt->execute([$_SESSION['user_id']]);
                $completed = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Récupérer tous les niveaux
                $stmt = $pdo->prepare("SELECT name FROM levels ORDER BY min_score");
                $stmt->execute();
                $allLevels = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $levelsCompleted = [];
                foreach ($allLevels as $level) {
                    $levelsCompleted[$level] = in_array($level, $completed);
                }
                
                // Récupérer les succès débloqués
                $stmt = $pdo->prepare("
                    SELECT a.name, a.description, a.icon, ua.unlocked_at
                    FROM user_achievements ua
                    JOIN achievements a ON ua.achievement_id = a.id
                    WHERE ua.user_id = ?
                    ORDER BY ua.unlocked_at DESC
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'stats' => [
                        'username' => $user['username'],
                        'games_played' => (int)$user['games_played'],
                        'total_score' => (int)$user['total_score'],
                        'level' => $user['current_level'],
                        'best_score' => (int)$user['best_score'],
                        'avg_score' => $user['games_played'] > 0 ? round($user['total_score'] / $user['games_played']) : 0,
                        'levels_completed' => $levelsCompleted,
                        'achievements' => $achievements
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
            }
            exit;
        }
        
        // =============================================
        // ACTION: GET_ACHIEVEMENTS
        // =============================================
        if ($action === 'get_achievements') {
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Non authentifié']);
                exit;
            }
            
            // Récupérer tous les succès avec indication de ceux débloqués
            $stmt = $pdo->prepare("
                SELECT a.*, 
                       CASE WHEN ua.id IS NOT NULL THEN true ELSE false END as unlocked,
                       ua.unlocked_at
                FROM achievements a
                LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = ?
                ORDER BY a.id
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'achievements' => $achievements]);
            exit;
        }
        
        // =============================================
        // ACTION: GET_LEVELS
        // =============================================
        if ($action === 'get_levels') {
            $stmt = $pdo->prepare("SELECT * FROM levels ORDER BY min_score");
            $stmt->execute();
            $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'levels' => $levels]);
            exit;
        }
        
    } catch (PDOException $e) {
        error_log("Erreur BD dans l'action $action: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        error_log("Erreur générale dans l'action $action: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
        exit;
    }
}

// =============================================
// FONCTION: Vérifier et débloquer les succès
// =============================================
function checkAchievements($userId, $pdo) {
    try {
        // Récupérer les stats de l'utilisateur
        $stmt = $pdo->prepare("SELECT games_played, current_level, best_score FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Récupérer les succès déjà débloqués
        $stmt = $pdo->prepare("SELECT achievement_id FROM user_achievements WHERE user_id = ?");
        $stmt->execute([$userId]);
        $unlocked = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Récupérer tous les succès
        $stmt = $pdo->prepare("SELECT * FROM achievements");
        $stmt->execute();
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($achievements as $achievement) {
            if (in_array($achievement['id'], $unlocked)) continue;
            
            $unlock = false;
            
            switch ($achievement['condition_type']) {
                case 'games':
                    if ($user['games_played'] >= $achievement['condition_value']) $unlock = true;
                    break;
                case 'level':
                    $levelMap = ['Debutant' => 1, 'Amateur' => 2, 'Maitre du Jeu' => 3, 'Alpha' => 4, 'Maitre Supreme' => 5];
                    $currentLevelValue = $levelMap[$user['current_level']] ?? 0;
                    if ($currentLevelValue >= $achievement['condition_value']) $unlock = true;
                    break;
                case 'score':
                    if ($user['best_score'] >= $achievement['condition_value']) $unlock = true;
                    break;
                case 'achievements':
                    // Complexe - à implémenter si nécessaire
                    break;
            }
            
            if ($unlock) {
                $stmt = $pdo->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)");
                $stmt->execute([$userId, $achievement['id']]);
            }
        }
    } catch (Exception $e) {
        error_log("Erreur checkAchievements: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUIZZ Game - Système de Niveaux</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Styles de connexion */
        .login-page {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-box, .register-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }

        .login-box h1, .register-box h1 {
            text-align: center;
            color: #667eea;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-success {
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-success:hover {
            background: #45a049;
        }

        .btn-secondary {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* Dashboard */
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .level-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(102, 126, 234, 0.3);
        }

        .btn-logout {
            padding: 8px 16px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-logout:hover {
            background: #d32f2f;
        }

        .dashboard-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 30px;
        }

        .welcome-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .stats-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .level-progression {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .level-path {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .level-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-width: 80px;
        }

        .level-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 5px;
            border: 3px solid transparent;
            transition: all 0.3s;
        }

        .level-node.completed .level-icon {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #4CAF50;
        }

        .level-node.current .level-icon {
            border-color: #ff9800;
            animation: pulse 1s infinite;
        }

        .level-node.locked .level-icon {
            background: #e0e0e0;
            color: #999;
        }

        .level-connector {
            flex: 1;
            height: 3px;
            background: #e0e0e0;
            margin: 0 10px;
            min-width: 20px;
        }

        .level-connector.completed {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .level-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 30px 0;
        }

        .level-btn {
            padding: 20px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            font-weight: 600;
        }

        .level-btn.unlocked {
            opacity: 1;
            cursor: pointer;
        }

        .level-btn.unlocked:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
            border-color: #667eea;
        }

        .level-btn.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .level-btn.locked {
            opacity: 0.4;
            cursor: not-allowed;
            background: #f5f5f5;
        }

        .ranking-section {
            margin-top: 40px;
        }

        .ranking-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            overflow-x: auto;
        }

        .ranking-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: white;
            position: relative;
            white-space: nowrap;
            transition: color 0.3s;
        }

        .ranking-tab:hover {
            color: #ffd700;
        }

        .ranking-tab.active {
            color: white;
            font-weight: 600;
        }

        .ranking-tab.active::after {
            content: '';
            position: absolute;
            bottom: -11px;
            left: 0;
            width: 100%;
            height: 3px;
            background: #ffd700;
        }

        .ranking-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .ranking-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .ranking-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }

        .ranking-table tr:hover {
            background: #f8f9fa;
        }

        .top-1 {
            background: linear-gradient(135deg, #ffd700 0%, #ffb347 100%);
        }

        .top-1 td {
            color: #000 !important;
            font-weight: bold;
        }

        .top-2 {
            background: linear-gradient(135deg, #c0c0c0 0%, #a9a9a9 100%);
        }

        .top-2 td {
            color: #000 !important;
            font-weight: bold;
        }

        .top-3 {
            background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%);
        }

        .top-3 td {
            color: #fff !important;
            font-weight: bold;
        }

        .user-position {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
        }

        .user-position td {
            color: #0d47a1 !important;
            font-weight: bold;
        }

        .top-1.user-position td,
        .top-2.user-position td,
        .top-3.user-position td {
            font-weight: bold;
        }

        /* Quiz */
        .quiz-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-top: 30px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #f0f0f0;
            border-radius: 5px;
            margin: 20px 0;
        }

        .progress {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 5px;
            transition: width 0.3s;
        }

        .options {
            display: grid;
            gap: 15px;
            margin: 30px 0;
        }

        .option {
            padding: 15px;
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            color: #333;
        }

        .option:hover {
            background: #e8eaf6;
            border-color: #667eea;
        }

        .option.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .navigation-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }

        .nav-btn {
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }

        .nav-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .nav-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        /* Résultats */
        .result-container {
            background: white;
            border-radius: 10px;
            padding: 40px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-top: 30px;
            text-align: center;
        }

        .score-circle {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin: 30px auto;
            color: white;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .score-number {
            font-size: 48px;
            font-weight: bold;
        }

        .unlock-message {
            background: #4CAF50;
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 18px;
            animation: slideIn 0.5s;
        }

        .achievement-unlock {
            background: #ffd700;
            color: #333;
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 16px;
            animation: slideIn 0.5s;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .hidden {
            display: none !important;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .text-center {
            text-align: center;
        }

        .mt-3 {
            margin-top: 15px;
        }

        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .achievement-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .achievement-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .achievement-card.unlocked {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .achievement-card.locked {
            opacity: 0.5;
            filter: grayscale(100%);
        }

        .achievement-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .achievement-name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .achievement-desc {
            font-size: 12px;
        }

        /* Améliorations responsives */
        @media (max-width: 768px) {
            .dashboard-content {
                grid-template-columns: 1fr;
            }
            
            .level-path {
                flex-direction: column;
                gap: 10px;
            }
            
            .level-connector {
                width: 3px;
                height: 20px;
                margin: 5px 0;
            }
            
            .level-node {
                width: 100%;
            }
            
            .ranking-tabs {
                flex-wrap: wrap;
            }
        }

        /* Tooltips */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            background: #333;
            color: white;
            font-size: 12px;
            border-radius: 5px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            z-index: 1000;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
            visibility: visible;
            bottom: 120%;
        }

        /* Améliorations pour le classement */
        .ranking-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .ranking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .ranking-title {
            font-size: 24px;
            color: white;
            margin-bottom: 20px;
        }

        .no-ranking {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
            color: #666;
            font-size: 16px;
        }

        .no-ranking i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 10px;
            display: block;
        }
    </style>
</head>
<body>
    <!-- Page de connexion -->
    <div id="loginPage" class="login-page">
        <div class="login-box">
            <h1>🎮 QUIZZ Game</h1>
            <h2>Connexion</h2>
            
            <div id="loginAlert" class="alert hidden"></div>
            
            <form id="loginForm">
                <div class="form-group">
                    <label for="username">Identifiant</label>
                    <input type="text" id="username" name="username" placeholder="Entrez votre identifiant" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="btn-primary" id="loginBtn">Se connecter</button>
            </form>
            
            <p class="register-link">
                Pas encore de compte ? <a href="#" onclick="showRegister()">S'inscrire</a>
            </p>
        </div>
        
        <div id="registerBox" class="register-box" style="display: none;">
            <h1>🎮 QUIZZ Game</h1>
            <h2>Inscription</h2>
            
            <div id="registerAlert" class="alert hidden"></div>
            
            <form id="registerForm">
                <div class="form-group">
                    <label for="reg_username">Identifiant</label>
                    <input type="text" id="reg_username" name="username" placeholder="Choisissez un identifiant" required>
                </div>
                
                <div class="form-group">
                    <label for="reg_email">Email</label>
                    <input type="email" id="reg_email" name="email" placeholder="exemple@email.com" required>
                </div>
                
                <div class="form-group">
                    <label for="reg_password">Mot de passe</label>
                    <input type="password" id="reg_password" name="password" placeholder="Minimum 6 caractères" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="reg_confirm">Confirmer le mot de passe</label>
                    <input type="password" id="reg_confirm" name="confirm" placeholder="Confirmez votre mot de passe" required>
                </div>
                
                <button type="submit" class="btn-primary" id="registerBtn">S'inscrire</button>
            </form>
            
            <p class="register-link">
                Déjà un compte ? <a href="#" onclick="showLogin()">Se connecter</a>
            </p>
        </div>
    </div>

    <!-- Dashboard principal -->
    <div id="dashboardPage" style="display: none;">
        <nav class="navbar">
            <div class="nav-container">
                <h2>🎮 QUIZZ Game</h2>
                <div class="user-info">
                    <span id="usernameDisplay"></span>
                    <span class="level-badge" id="userLevel">Debutant</span>
                    <button onclick="logout()" class="btn-logout">Déconnexion</button>
                </div>
            </div>
        </nav>

        <div class="container">
            <div class="dashboard-content">
                <div class="welcome-card">
                    <h2>Bienvenue, <span id="welcomeUsername"></span> !</h2>
                    <p>Progressez à travers les niveaux et devenez le meilleur !</p>
                    
                    <!-- Progression des niveaux -->
                    <div class="level-progression">
                        <h3>Votre progression</h3>
                        <div class="level-path" id="levelPath">
                            <div class="level-node" id="levelNodeDebutant">
                                <div class="level-icon" data-tooltip="Niveau débutant">🌱</div>
                                <span>Debutant</span>
                            </div>
                            <div class="level-connector" id="connector1"></div>
                            <div class="level-node" id="levelNodeAmateur">
                                <div class="level-icon" data-tooltip="Niveau amateur">🌟</div>
                                <span>Amateur</span>
                            </div>
                            <div class="level-connector" id="connector2"></div>
                            <div class="level-node" id="levelNodeMaitre">
                                <div class="level-icon" data-tooltip="Niveau maître du jeu">🎯</div>
                                <span>Maitre du Jeu</span>
                            </div>
                            <div class="level-connector" id="connector3"></div>
                            <div class="level-node" id="levelNodeAlpha">
                                <div class="level-icon" data-tooltip="Niveau alpha">⚡</div>
                                <span>Alpha</span>
                            </div>
                            <div class="level-connector" id="connector4"></div>
                            <div class="level-node" id="levelNodeSuprime">
                                <div class="level-icon" data-tooltip="Niveau maître suprême">👑</div>
                                <span>Maitre Supreme</span>
                            </div>
                        </div>
                    </div>
                    
                    <h3>Sélectionnez votre niveau</h3>
                    <div class="level-selector">
                        <div class="level-btn unlocked selected" onclick="selectLevel('Debutant', this)" data-tooltip="Commencez votre aventure ici">🌱 Debutant</div>
                        <div class="level-btn locked" onclick="selectLevel('Amateur', this)" id="levelAmateur" data-tooltip="Nécessite 90% au niveau débutant">🌟 Amateur</div>
                        <div class="level-btn locked" onclick="selectLevel('Maitre du Jeu', this)" id="levelMaitre" data-tooltip="Nécessite 90% au niveau amateur">🎯 Maitre du Jeu</div>
                        <div class="level-btn locked" onclick="selectLevel('Alpha', this)" id="levelAlpha" data-tooltip="Nécessite 90% au niveau maître du jeu">⚡ Alpha</div>
                        <div class="level-btn locked" onclick="selectLevel('Maitre Supreme', this)" id="levelSuprime" data-tooltip="Nécessite 90% au niveau alpha">👑 Maitre Supreme</div>
                    </div>

                    <button onclick="startQuiz()" class="btn-primary" style="width: auto; padding: 15px 40px;">Commencer le Quiz</button>
                </div>

                <div class="stats-card">
                    <h3>Vos Statistiques</h3>
                    <div id="userStats">
                        <p>Parties jouées: <span id="gamesPlayed">0</span></p>
                        <p>Meilleur score: <span id="bestScore">0%</span></p>
                        <p>Score moyen: <span id="avgScore">0%</span></p>
                    </div>
                    
                    <h3 style="margin-top: 20px;">🏆 Succès</h3>
                    <div id="achievementsPreview" class="achievements-grid" style="grid-template-columns: repeat(2, 1fr);">
                        <!-- Les succès seront chargés ici -->
                    </div>
                    <button onclick="showAchievements()" class="btn-success" style="margin-top: 10px; width: 100%;">Voir tous les succès</button>
                </div>
            </div>

            <!-- Section Classement par niveau -->
            <div class="ranking-section">
                <h2 class="ranking-title">🏆 Classements par niveau</h2>
                <div class="ranking-tabs">
                    <button class="ranking-tab active" onclick="switchRanking('Debutant', this)">Debutant</button>
                    <button class="ranking-tab" onclick="switchRanking('Amateur', this)">Amateur</button>
                    <button class="ranking-tab" onclick="switchRanking('Maitre du Jeu', this)">Maitre du Jeu</button>
                    <button class="ranking-tab" onclick="switchRanking('Alpha', this)">Alpha</button>
                    <button class="ranking-tab" onclick="switchRanking('Maitre Supreme', this)">Maitre Supreme</button>
                </div>
                <div id="rankingContainer">
                    <div class="text-center mt-3" style="color: white;">Chargement du classement...</div>
                </div>
            </div>

            <!-- Zone du quiz -->
            <div id="quizArea" class="quiz-container" style="display: none;">
                <div class="question-header">
                    <h3 id="currentLevel">Niveau: Debutant</h3>
                    <span id="questionCounter">Question 1/20</span>
                </div>
                
                <div class="progress-bar">
                    <div id="progress" class="progress" style="width: 0%"></div>
                </div>

                <h2 id="questionText">Question ici</h2>

                <div id="options" class="options"></div>

                <div class="navigation-buttons">
                    <button id="prevBtn" class="nav-btn" onclick="prevQuestion()" disabled>Précédent</button>
                    <button id="nextBtn" class="nav-btn" onclick="nextQuestion()">Suivant</button>
                </div>
            </div>

            <!-- Zone des résultats -->
            <div id="resultArea" class="result-container" style="display: none;">
                <h2>Résultats du Quiz</h2>
                
                <div class="score-circle">
                    <span class="score-number" id="finalScore">0</span>
                    <span>%</span>
                </div>

                <h3 id="finalLevel">Niveau: Debutant</h3>
                
                <div id="unlockMessage" class="unlock-message hidden"></div>
                <div id="achievementMessage" class="achievement-unlock hidden"></div>

                <div style="display: flex; gap: 10px; justify-content: center; margin-top: 30px;">
                    <button onclick="playAgain()" class="btn-primary" style="width: auto;">Rejouer ce niveau</button>
                    <button onclick="backToDashboard()" class="btn-secondary" style="width: auto;">Tableau de bord</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal des succès -->
    <div id="achievementsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; overflow-y: auto;">
        <div style="background: white; max-width: 800px; margin: 50px auto; padding: 30px; border-radius: 10px;">
            <h2 style="text-align: center; margin-bottom: 20px;">🏆 Tous les succès</h2>
            <div id="allAchievements" class="achievements-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
                <!-- Les succès seront chargés ici -->
            </div>
            <div style="display: flex; justify-content: center; margin-top: 20px;">
                <button onclick="closeAchievements()" class="btn-primary" style="width: auto; padding: 10px 30px;">Fermer</button>
            </div>
        </div>
    </div>

    <script>
        // État de l'application
        let currentUser = null;
        let currentLevel = 'Debutant';
        let currentQuestions = [];
        let currentQuestionIndex = 0;
        let userAnswers = [];
        let levelsCompleted = {
            'Debutant': true,
            'Amateur': false,
            'Maitre du Jeu': false,
            'Alpha': false,
            'Maitre Supreme': false
        };

        // Fonctions d'authentification
        function showRegister() {
            document.querySelector('.login-box').style.display = 'none';
            document.getElementById('registerBox').style.display = 'block';
            clearAlerts();
        }

        function showLogin() {
            document.getElementById('registerBox').style.display = 'none';
            document.querySelector('.login-box').style.display = 'block';
            clearAlerts();
        }

        function clearAlerts() {
            document.getElementById('loginAlert').classList.add('hidden');
            document.getElementById('registerAlert').classList.add('hidden');
        }

        function showAlert(elementId, message, type) {
            const alert = document.getElementById(elementId);
            alert.textContent = message;
            alert.className = `alert alert-${type}`;
            alert.classList.remove('hidden');
            
            setTimeout(() => {
                alert.classList.add('hidden');
            }, 5000);
        }

        function setLoading(buttonId, isLoading) {
            const btn = document.getElementById(buttonId);
            if (isLoading) {
                btn.disabled = true;
                btn.innerHTML = '<span class="loading"></span> Chargement...';
            } else {
                btn.disabled = false;
                btn.innerHTML = buttonId === 'loginBtn' ? 'Se connecter' : 'S\'inscrire';
            }
        }

        // Login
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                showAlert('loginAlert', 'Veuillez remplir tous les champs', 'error');
                return;
            }
            
            setLoading('loginBtn', true);
            
            try {
                const response = await fetch('?action=login', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ username, password })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    currentUser = result.user;
                    
                    document.getElementById('loginPage').style.display = 'none';
                    document.getElementById('dashboardPage').style.display = 'block';
                    
                    document.getElementById('usernameDisplay').textContent = currentUser.username;
                    document.getElementById('welcomeUsername').textContent = currentUser.username;
                    document.getElementById('userLevel').textContent = currentUser.level;
                    document.getElementById('gamesPlayed').textContent = currentUser.games_played;
                    document.getElementById('bestScore').textContent = currentUser.best_score + '%';
                    document.getElementById('avgScore').textContent = currentUser.avg_score + '%';
                    
                    unlockLevel('Debutant');
                    
                    // Charger les statistiques
                    loadUserStats();
                    
                    // Charger le classement
                    switchRanking('Debutant', document.querySelector('.ranking-tab'));
                    
                    // Charger les succès
                    loadAchievementsPreview();
                } else {
                    showAlert('loginAlert', result.message || 'Erreur de connexion', 'error');
                }
            } catch (error) {
                console.error('Login error:', error);
                showAlert('loginAlert', 'Erreur de connexion au serveur: ' + error.message, 'error');
            } finally {
                setLoading('loginBtn', false);
            }
        });

        // Register
        document.getElementById('registerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('reg_username').value.trim();
            const email = document.getElementById('reg_email').value.trim();
            const password = document.getElementById('reg_password').value;
            const confirm = document.getElementById('reg_confirm').value;
            
            if (!username || !email || !password || !confirm) {
                showAlert('registerAlert', 'Veuillez remplir tous les champs', 'error');
                return;
            }
            
            if (password.length < 6) {
                showAlert('registerAlert', 'Le mot de passe doit contenir au moins 6 caractères', 'error');
                return;
            }
            
            if (password !== confirm) {
                showAlert('registerAlert', 'Les mots de passe ne correspondent pas', 'error');
                return;
            }
            
            if (!email.includes('@') || !email.includes('.')) {
                showAlert('registerAlert', 'Email invalide', 'error');
                return;
            }
            
            setLoading('registerBtn', true);
            
            try {
                const response = await fetch('?action=register', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ username, email, password })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('registerAlert', result.message, 'success');
                    
                    setTimeout(() => {
                        showLogin();
                    }, 2000);
                } else {
                    showAlert('registerAlert', result.message || 'Erreur d\'inscription', 'error');
                }
            } catch (error) {
                console.error('Register error:', error);
                showAlert('registerAlert', 'Erreur de connexion au serveur: ' + error.message, 'error');
            } finally {
                setLoading('registerBtn', false);
            }
        });

        async function loadUserStats() {
            try {
                const response = await fetch('?action=get_user_stats');
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('gamesPlayed').textContent = result.stats.games_played;
                    document.getElementById('bestScore').textContent = result.stats.best_score + '%';
                    document.getElementById('avgScore').textContent = result.stats.avg_score + '%';
                    
                    // Mettre à jour les niveaux complétés
                    levelsCompleted = result.stats.levels_completed;
                    updateLevelPath();
                    
                    // Débloquer les niveaux dans l'interface
                    for (let level in levelsCompleted) {
                        if (levelsCompleted[level]) {
                            const levelMap = {
                                'Amateur': 'levelAmateur',
                                'Maitre du Jeu': 'levelMaitre',
                                'Alpha': 'levelAlpha',
                                'Maitre Supreme': 'levelSuprime'
                            };
                            
                            if (levelMap[level]) {
                                const btn = document.getElementById(levelMap[level]);
                                if (btn) {
                                    btn.classList.remove('locked');
                                    btn.classList.add('unlocked');
                                }
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Erreur chargement stats:', error);
            }
        }

        async function loadAchievementsPreview() {
            try {
                const response = await fetch('?action=get_achievements');
                const result = await response.json();
                
                if (result.success) {
                    const preview = document.getElementById('achievementsPreview');
                    preview.innerHTML = '';
                    
                    // Afficher seulement les 4 premiers succès
                    const achievements = result.achievements.slice(0, 4);
                    
                    achievements.forEach(ach => {
                        const card = document.createElement('div');
                        card.className = `achievement-card ${ach.unlocked ? 'unlocked' : 'locked'}`;
                        card.innerHTML = `
                            <div class="achievement-icon">${ach.icon || '🏆'}</div>
                            <div class="achievement-name">${ach.name}</div>
                            <div class="achievement-desc">${ach.description}</div>
                        `;
                        preview.appendChild(card);
                    });
                }
            } catch (error) {
                console.error('Erreur chargement succès:', error);
            }
        }

        function showAchievements() {
            document.getElementById('achievementsModal').style.display = 'block';
            loadAllAchievements();
        }

        async function loadAllAchievements() {
            try {
                const response = await fetch('?action=get_achievements');
                const result = await response.json();
                
                if (result.success) {
                    const container = document.getElementById('allAchievements');
                    container.innerHTML = '';
                    
                    result.achievements.forEach(ach => {
                        const card = document.createElement('div');
                        card.className = `achievement-card ${ach.unlocked ? 'unlocked' : 'locked'}`;
                        card.innerHTML = `
                            <div class="achievement-icon">${ach.icon || '🏆'}</div>
                            <div class="achievement-name">${ach.name}</div>
                            <div class="achievement-desc">${ach.description}</div>
                            ${ach.unlocked ? '<small>Débloqué le ' + new Date(ach.unlocked_at).toLocaleDateString() + '</small>' : ''}
                        `;
                        container.appendChild(card);
                    });
                }
            } catch (error) {
                console.error('Erreur chargement succès:', error);
            }
        }

        function closeAchievements() {
            document.getElementById('achievementsModal').style.display = 'none';
        }

        function logout() {
            currentUser = null;
            document.getElementById('dashboardPage').style.display = 'none';
            document.getElementById('loginPage').style.display = 'flex';
            document.querySelector('.login-box').style.display = 'block';
            document.getElementById('registerBox').style.display = 'none';
            
            document.getElementById('loginForm').reset();
            document.getElementById('registerForm').reset();
            clearAlerts();
        }

        function unlockLevel(level) {
            levelsCompleted[level] = true;
            
            const levelMap = {
                'Debutant': 'levelAmateur',
                'Amateur': 'levelMaitre',
                'Maitre du Jeu': 'levelAlpha',
                'Alpha': 'levelSuprime'
            };
            
            if (levelMap[level]) {
                const nextLevelBtn = document.getElementById(levelMap[level]);
                if (nextLevelBtn) {
                    nextLevelBtn.classList.remove('locked');
                    nextLevelBtn.classList.add('unlocked');
                }
            }
            
            updateLevelPath();
        }

        function updateLevelPath() {
            const levels = ['Debutant', 'Amateur', 'Maitre du Jeu', 'Alpha', 'Maitre Supreme'];
            const nodes = [
                document.getElementById('levelNodeDebutant'),
                document.getElementById('levelNodeAmateur'),
                document.getElementById('levelNodeMaitre'),
                document.getElementById('levelNodeAlpha'),
                document.getElementById('levelNodeSuprime')
            ];
            const connectors = [
                document.getElementById('connector1'),
                document.getElementById('connector2'),
                document.getElementById('connector3'),
                document.getElementById('connector4')
            ];
            
            let currentLevelIndex = levels.indexOf(currentUser?.level || 'Debutant');
            
            levels.forEach((level, index) => {
                nodes[index].classList.remove('completed', 'current', 'locked');
                
                if (levelsCompleted[level]) {
                    nodes[index].classList.add('completed');
                } else if (index === currentLevelIndex) {
                    nodes[index].classList.add('current');
                } else {
                    nodes[index].classList.add('locked');
                }
            });
            
            connectors.forEach((connector, index) => {
                if (levelsCompleted[levels[index]]) {
                    connector.classList.add('completed');
                } else {
                    connector.classList.remove('completed');
                }
            });
        }

        function selectLevel(level, element) {
            if (!levelsCompleted[level] && level !== currentUser?.level) {
                alert('Ce niveau n\'est pas encore débloqué ! Complétez le niveau précédent avec 90% de réussite.');
                return;
            }
            
            currentLevel = level;
            document.querySelectorAll('.level-btn').forEach(btn => btn.classList.remove('selected'));
            element.classList.add('selected');
        }

        async function startQuiz() {
            try {
                const response = await fetch(`?action=get_questions&level=${encodeURIComponent(currentLevel)}`);
                const result = await response.json();
                
                if (result.success && result.questions && result.questions.length > 0) {
                    currentQuestions = result.questions;
                    currentQuestionIndex = 0;
                    userAnswers = new Array(currentQuestions.length).fill(null);
                    
                    document.getElementById('quizArea').style.display = 'block';
                    document.querySelector('.dashboard-content').style.display = 'none';
                    document.querySelector('.ranking-section').style.display = 'none';
                    document.getElementById('resultArea').style.display = 'none';
                    
                    displayQuestion();
                } else {
                    alert(result.message || 'Aucune question disponible pour ce niveau');
                }
            } catch (error) {
                console.error('Quiz error:', error);
                alert('Erreur lors du chargement des questions: ' + error.message);
            }
        }

        function displayQuestion() {
            const question = currentQuestions[currentQuestionIndex];
            document.getElementById('currentLevel').textContent = `Niveau: ${currentLevel}`;
            document.getElementById('questionCounter').textContent = `Question ${currentQuestionIndex + 1}/${currentQuestions.length}`;
            document.getElementById('questionText').textContent = question.question;
            
            const progress = ((currentQuestionIndex + 1) / currentQuestions.length) * 100;
            document.getElementById('progress').style.width = progress + '%';
            
            const optionsDiv = document.getElementById('options');
            optionsDiv.innerHTML = '';
            
            question.options.forEach((option, index) => {
                const optionDiv = document.createElement('div');
                optionDiv.className = 'option';
                if (userAnswers[currentQuestionIndex] === index) {
                    optionDiv.classList.add('selected');
                }
                optionDiv.textContent = option;
                optionDiv.onclick = () => selectOption(index);
                optionsDiv.appendChild(optionDiv);
            });
            
            document.getElementById('prevBtn').disabled = currentQuestionIndex === 0;
            document.getElementById('nextBtn').textContent = 
                currentQuestionIndex === currentQuestions.length - 1 ? 'Terminer' : 'Suivant';
        }

        function selectOption(index) {
            userAnswers[currentQuestionIndex] = index;
            document.querySelectorAll('.option').forEach((opt, i) => {
                if (i === index) opt.classList.add('selected');
                else opt.classList.remove('selected');
            });
        }

        function nextQuestion() {
            if (userAnswers[currentQuestionIndex] === null) {
                alert('Veuillez sélectionner une réponse');
                return;
            }
            
            if (currentQuestionIndex < currentQuestions.length - 1) {
                currentQuestionIndex++;
                displayQuestion();
            } else {
                calculateScore();
            }
        }

        function prevQuestion() {
            if (currentQuestionIndex > 0) {
                currentQuestionIndex--;
                displayQuestion();
            }
        }

        async function calculateScore() {
            let correctAnswers = 0;
            currentQuestions.forEach((question, index) => {
                if (userAnswers[index] === question.correct) correctAnswers++;
            });
            
            const percentage = Math.round((correctAnswers / currentQuestions.length) * 100);
            
            try {
                const response = await fetch('?action=save_score', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        level: currentLevel,
                        score: correctAnswers,
                        total: currentQuestions.length,
                        percentage: percentage
                    })
                });
                
                const result = await response.json();
                
                if (result.unlocked && result.next_level) {
                    unlockLevel(result.next_level);
                    
                    const unlockMsg = document.getElementById('unlockMessage');
                    unlockMsg.textContent = `🎉 Félicitations ! Vous avez débloqué le niveau ${result.next_level} avec ${percentage}% de réussite !`;
                    unlockMsg.classList.remove('hidden');
                    
                    if (currentUser) {
                        currentUser.level = result.next_level;
                        document.getElementById('userLevel').textContent = result.next_level;
                    }
                }
                
                // Recharger les stats et succès
                loadUserStats();
                loadAchievementsPreview();
                
                // Vérifier si des succès ont été débloqués
                checkNewAchievements();
                
            } catch (error) {
                console.error('Save score error:', error);
            }
            
            document.getElementById('quizArea').style.display = 'none';
            document.getElementById('resultArea').style.display = 'block';
            document.getElementById('finalScore').textContent = percentage;
            document.getElementById('finalLevel').textContent = `Niveau: ${currentLevel} - Score: ${percentage}%`;
        }

        async function checkNewAchievements() {
            try {
                const response = await fetch('?action=get_achievements');
                const result = await response.json();
                
                if (result.success) {
                    const newUnlocked = result.achievements.filter(a => a.unlocked);
                    // Afficher un message si de nouveaux succès sont débloqués
                }
            } catch (error) {
                console.error('Erreur vérification succès:', error);
            }
        }

        async function switchRanking(level, element) {
            document.querySelectorAll('.ranking-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            element.classList.add('active');
            
            document.getElementById('rankingContainer').innerHTML = '<div class="text-center mt-3" style="color: white;">Chargement du classement...</div>';
            
            try {
                const response = await fetch(`?action=get_ranking&level=${encodeURIComponent(level)}`);
                const result = await response.json();
                
                if (result.success) {
                    if (result.ranking && result.ranking.length > 0) {
                        let rankingHTML = `<table class="ranking-table">
                            <thead>
                                <tr>
                                    <th>Position</th>
                                    <th>Joueur</th>
                                    <th>Meilleur score</th>
                                    <th>Parties jouées</th>
                                </tr>
                            </thead>
                            <tbody>`;
                        
                        result.ranking.forEach((player, index) => {
                            let rowClass = '';
                            if (index === 0) rowClass = 'top-1';
                            else if (index === 1) rowClass = 'top-2';
                            else if (index === 2) rowClass = 'top-3';
                            
                            if (currentUser && player.username === currentUser.username) {
                                rowClass += ' user-position';
                            }
                            
                            rankingHTML += `<tr class="${rowClass}">
                                <td><strong>#${index + 1}</strong></td>
                                <td><strong>${player.username}</strong></td>
                                <td><strong>${player.score}%</strong></td>
                                <td>${player.games}</td>
                            </tr>`;
                        });
                        
                        rankingHTML += '</tbody></table>';
                        document.getElementById('rankingContainer').innerHTML = rankingHTML;
                    } else {
                        document.getElementById('rankingContainer').innerHTML = '<div class="no-ranking"><i>📊</i><p>Aucun joueur dans ce classement pour le moment</p><p>Soyez le premier à jouer à ce niveau !</p></div>';
                    }
                } else {
                    document.getElementById('rankingContainer').innerHTML = '<div class="alert alert-error">' + (result.message || 'Erreur de chargement') + '</div>';
                }
            } catch (error) {
                console.error('Ranking error:', error);
                document.getElementById('rankingContainer').innerHTML = '<div class="alert alert-error">Erreur de chargement du classement: ' + error.message + '</div>';
            }
        }

        function playAgain() {
            document.getElementById('resultArea').style.display = 'none';
            document.getElementById('unlockMessage').classList.add('hidden');
            document.getElementById('achievementMessage').classList.add('hidden');
            startQuiz();
        }

        function backToDashboard() {
            document.getElementById('resultArea').style.display = 'none';
            document.getElementById('quizArea').style.display = 'none';
            document.getElementById('unlockMessage').classList.add('hidden');
            document.getElementById('achievementMessage').classList.add('hidden');
            document.querySelector('.dashboard-content').style.display = 'grid';
            document.querySelector('.ranking-section').style.display = 'block';
            
            const activeTab = document.querySelector('.ranking-tab.active');
            if (activeTab) {
                switchRanking(currentLevel, activeTab);
            }
        }

        // Initialisation
        window.onload = function() {
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
            
            updateLevelPath();
        };

        // Fermer le modal des succès en cliquant en dehors
        window.onclick = function(event) {
            const modal = document.getElementById('achievementsModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        };
    </script>
</body>
</html>
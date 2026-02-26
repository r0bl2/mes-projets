<?php
$host = "localhost";
$db   = "langageC";
$user = "postgres";
$pass = "postgresql"; // mot de passe

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur connexion DB : " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $auteur  = $_POST['auteur'];
    $message = $_POST['message'];

    // On récupère l'utilisateur test (id=1)
    $user_id = 1; // Doit correspondre à un utilisateur existant

    $fichier = null;
    $type = null;

    if (!empty($_FILES['media']['name'])) {
        $nom = $_FILES['media']['name'];
        $tmp = $_FILES['media']['tmp_name'];
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg','jpeg','png','gif'])) $type = "image";
        elseif (in_array($ext, ['mp4','avi','mov'])) $type = "video";

        if ($type) {
            if (!is_dir("uploads")) mkdir("uploads", 0777, true);
            $nouveau = time() . "_" . preg_replace("/[^a-zA-Z0-9_\.-]/", "_", $nom);
            move_uploaded_file($tmp, "uploads/" . $nouveau);
            $fichier = $nouveau;
        }
    }

    $sql = "INSERT INTO posts(user_id, auteur, message, fichier, type_media) VALUES(?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $auteur, $message, $fichier, $type]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mini Réseau Social</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family: 'Poppins', sans-serif; }
body { background: linear-gradient(135deg, #f5f7fa, #c3cfe2); color: #333; padding: 20px; min-height: 100vh; }
h2 { text-align:center; margin-bottom:30px; color:#34495e; text-shadow: 2px 2px 4px rgba(0,0,0,0.1); font-size:2.4em; }
form { background: #ffffffcc; padding:25px; border-radius:20px; box-shadow: 0 8px 20px rgba(0,0,0,0.15); max-width:600px; margin:0 auto 40px auto; transition: transform 0.3s ease, box-shadow 0.3s ease; backdrop-filter: blur(5px); }
form:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.2); }
input[type="text"], textarea, input[type="file"] { width:100%; padding:12px; margin:8px 0 18px 0; border-radius:12px; border:1px solid #ccc; font-size:16px; transition: border 0.3s, box-shadow 0.3s; }
input[type="text"]:focus, textarea:focus { border-color:#3498db; box-shadow:0 0 8px rgba(52,152,219,0.3); outline:none; }
button { background: linear-gradient(45deg, #6a11cb, #2575fc); color:white; padding:14px 25px; border:none; border-radius:12px; cursor:pointer; font-size:16px; transition: background 0.3s, transform 0.2s, box-shadow 0.3s; width:100%; }
button:hover { background: linear-gradient(45deg, #2575fc, #6a11cb); transform: scale(1.05); box-shadow:0 6px 20px rgba(0,0,0,0.2); }
h3 { text-align:center; margin-bottom:20px; color:#2c3e50; font-size:1.8em; }
.post { background: #ffffffcc; padding:20px 25px; border-radius:20px; margin:20px auto; max-width:650px; box-shadow: 0 8px 15px rgba(0,0,0,0.12); animation: fadeInUp 0.8s ease; transition: transform 0.3s, box-shadow 0.3s; backdrop-filter: blur(5px); }
.post:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.2); }
.post b { color:#34495e; font-size:1.1em; }
.post img, .post video { display:block; margin:12px 0; max-width:100%; border-radius:12px; }
.post small { color:#666; display:block; margin-top:12px; font-style:italic; }
@keyframes fadeInUp { from {opacity:0; transform:translateY(15px);} to {opacity:1; transform:translateY(0);} }
@media (max-width:700px) { body { padding:10px; } form, .post { width:95%; } }
</style>
</head>
<body>

<h2>🌟 Mini Réseau Social 🌟</h2>

<form method="post" enctype="multipart/form-data">
    <label>Auteur :</label><br>
    <input type="text" name="auteur" placeholder="Ton pseudo" required><br>

    <label>Message :</label><br>
    <textarea name="message" rows="4" placeholder="Écris quelque chose..." required></textarea><br>

    <label>Image / Vidéo (optionnel) :</label><br>
    <input type="file" name="media" accept="image/*,video/*"><br>

    <button type="submit">Publier 🌈</button>
</form> 

<h3>Publications récentes</h3>

<?php
$stmt = $pdo->query("SELECT * FROM posts ORDER BY date_pub DESC");
while($p = $stmt->fetch()) {
    echo "<div class='post'>";
    echo "<b>🌟 " . htmlspecialchars($p['auteur']) . "</b><br>";
    echo nl2br(htmlspecialchars($p['message'])) . "<br>";

    if ($p['fichier']) {
        if ($p['type_media'] == "image") echo "<img src='uploads/".$p['fichier']."' alt='image'>";
        if ($p['type_media'] == "video") echo "<video src='uploads/".$p['fichier']."' controls></video>";
    }

    echo "<small>Publié le ".$p['date_pub']."</small>";
    echo "</div>";
}
?>
</body>
</html>

<?php
session_start();

// Database config
$dbhost = '103.150.116.72'; // Ganti dengan host database Anda jika berbeda
$dbuser = 'absensi_user';      // Ganti dengan username database Anda
$dbpass = 'Teisuryacipta1.';     // Ganti dengan password database Anda
$dbname = 'lab_borrowing'; // Nama database yang telah Anda buat

// Create connection
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$message = '';
$message_type = '';

// Handle flash message from session
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Handle laporan kerusakan
if (isset($_POST['action']) && isset($_SESSION['student'])) {
    $component_id = intval($_POST['component_id']);
    $student_id = $_SESSION['student']['id'];
    $description = $conn->real_escape_string($_POST['description']);
    $damaged_quantity = intval($_POST['damaged_quantity']);

    $conn->begin_transaction();
    try {
        // 1. Dapatkan dan kunci data terkait
        $stmt = $conn->prepare("SELECT br.id, br.berjumlah, c.available_quantity 
                              FROM borrow_records br
                              JOIN components c ON br.component_id = c.id
                              WHERE br.student_id = ? 
                              AND br.component_id = ? 
                              AND br.return_time IS NULL 
                              ORDER BY br.id DESC LIMIT 1 FOR UPDATE");
        $stmt->bind_param("ii", $student_id, $component_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();

        // Validasi
        if (!$data) throw new Exception("Tidak ada peminjaman aktif!");
        if ($damaged_quantity < 1 || $damaged_quantity > $data['berjumlah']) {
            throw new Exception("Jumlah rusak tidak valid!");
        }

        // 2. Update borrow record
        $stmt = $conn->prepare("UPDATE borrow_records 
                              SET damaged_quantity = damaged_quantity + ?,
                                  berjumlah = berjumlah - ?
                              WHERE id = ?");
        $stmt->bind_param("iii", $damaged_quantity, $damaged_quantity, $data['id']);
        $stmt->execute();

        // 3. Update component stock
        $stmt = $conn->prepare("UPDATE components 
                              SET available_quantity = available_quantity - ?,
                                  damaged_quantity = damaged_quantity + ?
                              WHERE id = ?");
        $stmt->bind_param("iii", $damaged_quantity, $damaged_quantity, $component_id);
        $stmt->execute();

        // 4. Buat damage report
        $stmt = $conn->prepare("INSERT INTO damage_reports 
                              (component_id, student_id, borrow_id, quantity, description)
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiis", 
            $component_id,
            $student_id,
            $data['id'],
            $damaged_quantity,
            $description
        );
        $stmt->execute();

        $conn->commit();
        $_SESSION['flash_message'] = "Laporan kerusakan berhasil dicatat!";
        $_SESSION['flash_message_type'] = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message'] = "Error: " . $e->getMessage();
        $_SESSION['flash_message_type'] = 'error';
    }
    
    header("Location: report_damage.php?component_id=" . $component_id);
    exit();
}

// Get component details
$component_id = isset($_GET['component_id']) ? intval($_GET['component_id']) : 0;
$stmt = $conn->prepare("SELECT * FROM components WHERE id = ?");
$stmt->bind_param("i", $component_id);
$stmt->execute();
$component = $stmt->get_result()->fetch_assoc();

// Get current borrowed quantity
$borrowed_quantity = 0;
if (isset($_SESSION['student'])) {
    $stmt = $conn->prepare("SELECT berjumlah FROM borrow_records 
                          WHERE student_id = ? AND component_id = ? 
                          AND return_time IS NULL 
                          ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ii", $_SESSION['student']['id'], $component_id);
    $stmt->execute();
    $borrowed = $stmt->get_result()->fetch_assoc();
    $borrowed_quantity = $borrowed ? $borrowed['berjumlah'] : 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Kerusakan Komponen</title>
    <link rel="icon" type="image/png" href="https://cdn-icons-png.flaticon.com/512/1087/1087848.png" />
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f0f2f5; }
        header { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; }
        .container { max-width: 600px; margin: 2rem auto; padding: 1rem; background: white; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .notification { padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        textarea { width: 100%; padding: 0.5rem; margin: 0.5rem 0; border: 1px solid #ddd; border-radius: 4px; }
        input[type="number"] { width: 100%; padding: 0.5rem; margin: 0.5rem 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #3498db; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; transition: background 0.3s; }
        button:hover { background: #2980b9; }
        .keyboard { display: grid; grid-template-columns: repeat(10, 1fr); gap: 5px; margin-top: 10px; }
        .key { padding: 10px; background: #3498db; color: white; text-align: center; cursor: pointer; border-radius: 5px; }
        .key:hover { background: #2980b9; }
        .red-button {
		background: #e74c3c; /* Warna merah */
		color: white; /* Teks berwarna putih */
		border: none; /* Tanpa border */
		padding: 0.5rem 1rem; /* Padding */
		border-radius: 5px; /* Sudut membulat */
		cursor: pointer; /* Pointer saat hover */
		transition: background 0.3s; /* Transisi saat hover */
		}
		.red-button:hover {
		background: #c0392b; /* Warna merah lebih gelap saat hover */
		}
		footer {
		background: #2c3e50; /* Warna latar belakang footer */
		color: white; /* Warna teks */
		padding: 1rem; /* Padding di dalam footer */
		text-align: center; /* Teks di tengah */
		margin-top: 2rem; /* Jarak atas footer */
		border-radius: 0 0 10px 10px; /* Sudut membulat */
		}
		
</style>
    <script>
		function increaseQuantity() {
			var quantityInput = document.getElementById('damaged_quantity');
			var currentValue = parseInt(quantityInput.value) || 0;
			quantityInput.value = Math.min(currentValue + 1, parseInt(quantityInput.max));
		}

		function decreaseQuantity() {
			var quantityInput = document.getElementById('damaged_quantity');
			var currentValue = parseInt(quantityInput.value) || 0;
			quantityInput.value = Math.max(currentValue - 1, 1);
		}

        function addToDescription(value) {
            var descriptionInput = document.getElementById('description');
            descriptionInput.value += value;
        }

        function backspace() {
            var descriptionInput = document.getElementById('description');
            descriptionInput.value = descriptionInput.value.slice(0, -1);
        }
    </script>
</head>
<body>
    <header>
        <h1>Laporan Kerusakan Alat atau Bahan</h1>
    </header>
    <div class="container">
        <?php if($message): ?>
            <div class="notification <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <h2>Alat/Bahan: <?= htmlspecialchars($component['name']) ?></h2>
        <p><strong>Jumlah Yang Dipinjam:</strong> <?= $borrowed_quantity ?></p>
        <form method="POST">
            <input type="hidden" name="component_id" value="<?= $component_id ?>">
		<label for="damaged_quantity">Jumlah yang rusak:</label>
		<div style="display: flex; align-items: center;">
		<button type="button" onclick="decreaseQuantity()">-</button>
		<input type="number" id="damaged_quantity" name="damaged_quantity" min="1" max="<?= $borrowed_quantity ?>" value="1" required style="width: 60px; text-align: center;">
		<button type="button" onclick="increaseQuantity()">+</button>
	</div>
      <label for="description">Deskripsi Kerusakan:</label>
      <textarea id="description" name="description" rows="4" required></textarea>
<div class="keyboard">
    <div class="key" onclick="addToDescription('Q')">Q</div>
    <div class="key" onclick="addToDescription('W')">W</div>
    <div class="key" onclick="addToDescription('E')">E</div>
    <div class="key" onclick="addToDescription('R')">R</div>
    <div class="key" onclick="addToDescription('T')">T</div>
    <div class="key" onclick="addToDescription('Y')">Y</div>
    <div class="key" onclick="addToDescription('U')">U</div>
    <div class="key" onclick="addToDescription('I')">I</div>
    <div class="key" onclick="addToDescription('O')">O</div>
    <div class="key" onclick="addToDescription('P')">P</div>
    <div class="key" onclick="addToDescription('A')">A</div>
    <div class="key" onclick="addToDescription('S')">S</div>
    <div class="key" onclick="addToDescription('D')">D</div>
    <div class="key" onclick="addToDescription('F')">F</div>
    <div class="key" onclick="addToDescription('G')">G</div>
    <div class="key" onclick="addToDescription('H')">H</div>
    <div class="key" onclick="addToDescription('J')">J</div>
    <div class="key" onclick="addToDescription('K')">K</div>
    <div class="key" onclick="addToDescription('L')">L</div>
    <div class="key" onclick="addToDescription('Z')">Z</div>
    <div class="key" onclick="addToDescription('X')">X</div>
    <div class="key" onclick="addToDescription('C')">C</div>
    <div class="key" onclick="addToDescription('V')">V</div>
    <div class="key" onclick="addToDescription('B')">B</div>
    <div class="key" onclick="addToDescription('N')">N</div>
    <div class="key" onclick="addToDescription('M')">M</div>
    <div class="key" onclick="addToDescription(',')">,</div>
    <div class="key" onclick="addToDescription('.')">.</div>
    <div class="key" onclick="addToDescription(' ')">SPC</div>
    <div class="key" onclick="backspace()">◄-</div>
    <div class="key" onclick="addToDescription('1')">1</div>
    <div class="key" onclick="addToDescription('2')">2</div>
    <div class="key" onclick="addToDescription('3')">3</div>
    <div class="key" onclick="addToDescription('4')">4</div>
    <div class="key" onclick="addToDescription('5')">5</div>
    <div class="key" onclick="addToDescription('6')">6</div>
    <div class="key" onclick="addToDescription('7')">7</div>
    <div class="key" onclick="addToDescription('8')">8</div>
    <div class="key" onclick="addToDescription('9')">9</div>
    <div class="key" onclick="addToDescription('0')">0</div>
            </div>
            <button type="submit" name="action" value="report_damage">Kirim Laporan</button>
			<a href="index.php" class="red-button" style="margin-top: 10px; display: inline-block;">Kembali ke Menu Peminjaman</a>
     </form>
    </div>
<footer>
    <p>
        <?php
        // Menampilkan tanggal saat ini
        date_default_timezone_set('Asia/Jakarta'); // Set timezone sesuai kebutuhan
        echo "Tanggal: " . date('d-m-Y') . " | Jam: ";
        ?>
        <span id="clock"></span>
    </p>
    <p>
        "Tanggung jawab mencerminkan kejujuran diri."
    </p>
    <p>
        &copy; <?= date('Y') ?> Dibuat oleh Angkatan 3 TEI
    </p>
</footer>

<script>
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('clock').innerHTML = hours + ':' + minutes + ':' + seconds;
    }

    setInterval(updateClock, 1000);
    window.onload = updateClock; // Update clock immediately on load
</script>
</body>
</html>
<?php $conn->close(); ?>

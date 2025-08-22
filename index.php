<?php
session_start();

// Database config
$dbhost = '103.150.116.72'; // Ganti dengan host database Anda jika berbeda
$dbuser = 'absensi_user';      // Ganti dengan username database Anda
$dbpass = 'Teisuryacipta1.';     // Ganti dengan password database Anda
$dbname = 'lab_borrowing'; // Nama database yang telah Anda buat

// Membuat koneksi ke database
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

// Memeriksa koneksi
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Inisialisasi
$message = '';
$message_type = '';
$current_student = null;
$selected_lab = isset($_GET['lab']) ? intval($_GET['lab']) : null;

// Pagination variables
$items_per_page = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Handle flash message from session
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['student']);
    session_destroy();
    header("Location: ./");
    exit;
}

// Handle RFID scan
if (isset($_POST['rfid_uid'])) {
    $rfid_uid = $conn->real_escape_string($_POST['rfid_uid']);
    $result = $conn->query("SELECT * FROM students WHERE rfid_uid = '$rfid_uid'");
    if ($result->num_rows === 1) {
        $_SESSION['student'] = $result->fetch_assoc();
        header("Location: ./");
        exit();
    } else {
        $message = "Data Siswa/Siswi Tidak Terdaftar!";
        $message_type = 'error';
    }
}

// Handle peminjaman/pengembalian
if (isset($_POST['action']) && isset($_SESSION['student'])) {
    $component_id = intval($_POST['component_id']);
    $student_id = $_SESSION['student']['id'];

    if ($_POST['action'] === 'borrow') {
        $quantity = intval($_POST['quantity']);

        // Mulai transaksi
        $conn->begin_transaction();
        try {
            // Dapatkan stok yang tersedia dari tabel components
            $stmt = $conn->prepare("SELECT available_quantity FROM components WHERE id = ?");
            $stmt->bind_param("i", $component_id);
            $stmt->execute();
            $component = $stmt->get_result()->fetch_assoc();

            if ($component && $component['available_quantity'] >= $quantity) {
                // Tambahkan baris pinjaman baru
                $stmt = $conn->prepare("INSERT INTO borrow_records (student_id, component_id, borrow_time, berjumlah) VALUES (?, ?, NOW(), ?)");
                $stmt->bind_param("iii", $student_id, $component_id, $quantity);
                $stmt->execute();

                // Kurangi stok di tabel components
                $new_quantity = $component['available_quantity'] - $quantity;
                $stmt = $conn->prepare("UPDATE components SET available_quantity = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_quantity, $component_id);
                $stmt->execute();

                $conn->commit();

                $_SESSION['flash_message'] = "Peminjaman $quantity alat/bahan berhasil!";
                $_SESSION['flash_message_type'] = 'success';
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "Stok tidak mencukupi untuk $quantity alat/bahan!";
                $_SESSION['flash_message_type'] = 'error';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Terjadi kesalahan: " . $e->getMessage();
            $_SESSION['flash_message_type'] = 'error';
        }
    } elseif ($_POST['action'] === 'return_all') {
        $conn->begin_transaction();
        try {
            // Ambil semua peminjaman yang belum dikembalikan milik siswa dan komponen tersebut
            $stmt = $conn->prepare("SELECT id, berjumlah FROM borrow_records WHERE student_id = ? AND component_id = ? AND return_time IS NULL");
            $stmt->bind_param("ii", $student_id, $component_id);
            $stmt->execute();
            $borrows = $stmt->get_result();

            while ($borrow = $borrows->fetch_assoc()) {
                // Set tanggal kembali di catatan peminjaman
                $stmt2 = $conn->prepare("UPDATE borrow_records SET return_time = NOW() WHERE id = ?");
                $stmt2->bind_param("i", $borrow['id']);
                $stmt2->execute();

                // Tambahkan kembali stok di tabel components
                $stmt = $conn->prepare("SELECT available_quantity FROM components WHERE id = ?");
                $stmt->bind_param("i", $component_id);
                $stmt->execute();
                $component = $stmt->get_result()->fetch_assoc();

                $new_quantity = $component['available_quantity'] + $borrow['berjumlah'];
                $stmt = $conn->prepare("UPDATE components SET available_quantity = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_quantity, $component_id);
                $stmt->execute();
            }

            $conn->commit();
            $_SESSION['flash_message'] = "Semua alat/bahan berhasil dikembalikan!";
            $_SESSION['flash_message_type'] = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Terjadi kesalahan: " . $e->getMessage();
            $_SESSION['flash_message_type'] = 'error';
        }
    }
    header("Location: ".$_SERVER['HTTP_REFERER']);
    exit();
}

// Fungsi helper
function getLabs($conn) {
    return $conn->query("SELECT * FROM labs ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

function getLabComponents($conn, $lab_id, $offset, $items_per_page) {
    $stmt = $conn->prepare("SELECT * FROM components WHERE lab_id = ? LIMIT ?, ?");
    $stmt->bind_param("iii", $lab_id, $offset, $items_per_page);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getTotalComponents($conn, $lab_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM components WHERE lab_id = ?");
    $stmt->bind_param("i", $lab_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getBorrowedItems($conn, $student_id) {
    // Mengambil komponen yang DIPINJAM siswa, group by komponen
    $stmt = $conn->prepare("SELECT c.id, c.name, l.name AS lab_name, SUM(br.berjumlah) AS total_quantity 
                             FROM borrow_records br
                             JOIN components c ON br.component_id = c.id
                             JOIN labs l ON c.lab_id = l.id
                             WHERE br.student_id = ? AND br.return_time IS NULL
                             GROUP BY c.id");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Alat dan Bahan Laboratorium SMK SURYACIPTA</title>
    <meta name="description" content="Sistem Peminjaman Alat dan Bahan Laboratorium SMK SURYACIPTA" />
    <link rel="icon" type="image/png" href="https://cdn-icons-png.flaticon.com/512/1087/1087848.png" />

    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f0f2f5; }
        header { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
        .lab-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-top: 1rem; }
        .lab-card { background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; padding: 1.5rem; text-align: center; transition: transform 0.2s; cursor: pointer; text-decoration: none; color: inherit; }
        .lab-card:hover { transform: translateY(-3px); border-color: #3498db; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem;}
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #eee;}
        .notification { padding: 1rem; border-radius: 5px; margin-bottom: 1rem;}
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb;}
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;}
        button { background: #3498db; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; transition: background 0.3s; margin: 0.25rem;}
        button:hover { background: #2980b9; }
        .back-button { display: inline-block; margin-bottom: 1rem; text-decoration: none; color: #3498db; font-weight: bold;}
        .input-group { margin: 1rem 0;}
        input[type="text"], input[type="number"] { width: 100%; padding: 0.8rem; margin: 0.5rem 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;}
        footer {
            background: #2c3e50;
            color: white;
            padding: 1rem;
            text-align: center;
            margin-top: 2rem;
            border-radius: 0 0 10px 10px;
        }
        .red-button {
            background: #e74c3c; /* Red background */
            color: white; /* White text */
            border: none; /* Remove border */
            padding: 0.5rem 1rem; /* Add padding */
            border-radius: 5px; /* Rounded corners */
            cursor: pointer; /* Pointer cursor on hover */
            transition: background 0.3s; /* Smooth background transition */
        }
        .red-button:hover {
            background: #c0392b; /* Darker red on hover */
        }
        .blue-button {
            background: #3498db; /* Blue background */
            color: white; /* White text */
            border: none; /* Remove border */
            padding: 0.5rem 1rem; /* Add padding */
            border-radius: 5px; /* Rounded corners */
            cursor: pointer; /* Pointer cursor on hover */
            transition: background 0.3s; /* Smooth background transition */
        }
        .blue-button:hover {
            background: #2980b9; /* Darker blue on hover */
        }
        .pagination { margin-top: 1rem; }
        .pagination a { 
            margin: 0 5px; 
            text-decoration: none; 
            color: white; 
            background: #3498db; 
            padding: 0.5rem 1rem; 
            border-radius: 5px; 
            transition: background 0.3s; 
        }
        .pagination a:hover { background: #2980b9; }
        .pagination a.active { 
            font-weight: bold; 
            background: #2980b9; 
        }
        .profile-info {
        margin: 1rem 0; /* Margin for spacing */
        }

        .profile-row {
            display: flex; /* Use flexbox for alignment */
            justify-content: flex-start; /* Align items to the start */
            padding: 0.25rem 0; /* Padding for spacing */
        }

        .profile-row strong {
            margin-right: 5px; /* Space between label and value */
            width: 70px; /* Fixed width for labels to align them */
        }

    </style>
    <script>
        function changeQuantity(componentId, change) {
            var quantityInput = document.getElementById('quantity-' + componentId);
            var currentQuantity = parseInt(quantityInput.value);
            var newQuantity = currentQuantity + change;

            // Ensure quantity is within valid range
            if (newQuantity >= 1) {
                quantityInput.value = newQuantity;
            }
        }
    </script>
</head>
<body>
    <header>
        <h1>PEMINJAMAN ALAT DAN BAHAN LABORATORIUM</h1>
        </header>
        <div class="container">
        <?php if(isset($_SESSION['student'])): ?>
            <div class="card">
            <h2>Profil Siswa</h2>
        <div class="profile-info">
            <div class="profile-row">
                <strong>Nama</strong>:&nbsp<span><?= htmlspecialchars($_SESSION['student']['name']) ?></span>
            </div>
            <div class="profile-row">
                <strong>NIS</strong>:&nbsp<span><?= htmlspecialchars($_SESSION['student']['nis']) ?></span>
            </div>
            <div class="profile-row">
                <strong>Jurusan</strong>:&nbsp<span><?= htmlspecialchars($_SESSION['student']['department']) ?></span>
            </div>
            </div>
                <a href="?logout=1" class="red-button">Logout</a>
            </div>
            <?php if(!$selected_lab): ?>
                <div class="card">
                    <h2>Pilih Laboratorium</h2>
                    <div class="lab-grid">
                        <?php foreach(getLabs($conn) as $lab): ?>
                            <a href="?lab=<?= $lab['id'] ?>" class="lab-card">
                                <h3><?= htmlspecialchars($lab['name']) ?></h3>
                                <p><?= htmlspecialchars($lab['description']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="./" class="blue-button">← Kembali ke Daftar Lab</a>
                <div class="card">
                    <h2>Daftar Alat dan Bahan Yang Tersedia</h2>
                    <?php if($message): ?>
                        <div class="notification <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Kategori</th>
                                <th>Stok</th>
                                <th>Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_components = getTotalComponents($conn, $selected_lab);
                            $components = getLabComponents($conn, $selected_lab, $offset, $items_per_page);
                            foreach($components as $component):
                                $stmt = $conn->prepare("SELECT available_quantity FROM components WHERE id = ?");
                                $stmt->bind_param("i", $component['id']);
                                $stmt->execute();
                                $stock = $stmt->get_result()->fetch_assoc();
                                $available = $stock['available_quantity'] ?? 0;
                                $actual = $component['actual_quantity'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($component['name']) ?></td>
                                <td><?= htmlspecialchars($component['description']) ?></td>
                                <td><?= max(0, $available) ?> / <?= $actual ?></td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="component_id" value="<?= $component['id'] ?>">
                                        <button type="button" class="quantity-button" onclick="changeQuantity(<?= $component['id'] ?>, -1)">-</button>
                                        <input type="number" id="quantity-<?= $component['id'] ?>" name="quantity" value="1" min="1" max="<?= $available ?>" style="width: 60px; padding: 0.3rem;" readonly>
                                        <button type="button" class="quantity-button" onclick="changeQuantity(<?= $component['id'] ?>, 1)">+</button>
                                        <button type="submit" name="action" value="borrow" <?= $available < 1 ? 'disabled' : '' ?>>Pinjam</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php
                        $total_pages = ceil($total_components / $items_per_page);
                        for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?lab=<?= $selected_lab ?>&page=<?= $i ?>" class="<?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card">
                <h2>Alat Atau Bahan Yang Telah Dipinjam</h2>
                <?php $borrowed = getBorrowedItems($conn, $_SESSION['student']['id']); ?>
                <?php if(empty($borrowed)): ?>
                    <p>Belum ada alat/bahan yang dipinjam</p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>Nama Alat/Bahan</th>
                            <th>Lab</th>
                            <th>Jumlah</th>
                            <th>Tindakan</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach($borrowed as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                                <td><?= htmlspecialchars($item['lab_name']) ?></td>
                                <td><?= $item['total_quantity'] ?></td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="component_id" value="<?= $item['id'] ?>">
                                        <button type="submit" name="action" value="return_all" class="red-button">Kembalikan Semua</button>
                                        <!-- Di bagian daftar komponen dipinjam -->
                                        <a href="report_damage.php?component_id=<?= $item['id'] ?>" class="red-button">
                                          Laporkan Kerusakan
                                        </a>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Scan Kartu Pelajar SMK SURYACIPTA</h2>
                <?php if($message): ?>
                    <div class="notification <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="input-group">
                        <input type="text" name="rfid_uid" placeholder="Tempelkan Kartu Pelajar Anda..." required autofocus>
                    </div>
                    <button type="submit">Proses</button>
                </form>
            </div>
        <?php endif; ?>
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
        "Belajar adalah kunci untuk membuka pintu kesuksesan."
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


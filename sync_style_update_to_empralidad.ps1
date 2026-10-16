$user = "Antigravity@empralidad.com.ar"
$pass = "Actualizando_sitios57"
$baseUri = "ftp://ftp.empralidad.com.ar/"

function Upload-FtpFile($localPath, $remoteRelPath) {
    $uri = $baseUri + $remoteRelPath
    $wc = New-Object System.Net.WebClient
    $wc.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
    Write-Output "Uploading $localPath -> $remoteRelPath..."
    $wc.UploadFile($uri, $localPath)
    Write-Output "  [OK] $remoteRelPath"
}

Write-Output "Step 1: Uploading updated files to root emp-caja..."
Upload-FtpFile "C:\Users\Usuario\emp-caja\emp-caja.php" "wp-content/plugins/emp-caja/emp-caja.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\css\caja-style.css" "wp-content/plugins/emp-caja/assets/css/caja-style.css"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\js\caja-app.js" "wp-content/plugins/emp-caja/assets/js/caja-app.js"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-products.php" "wp-content/plugins/emp-caja/includes/class-caja-products.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-orders.php" "wp-content/plugins/emp-caja/includes/class-caja-orders.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-ajax.php" "wp-content/plugins/emp-caja/includes/class-caja-ajax.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\tab-products.php" "wp-content/plugins/emp-caja/templates/tab-products.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\dashboard.php" "wp-content/plugins/emp-caja/templates/dashboard.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\tab-pos-sale.php" "wp-content/plugins/emp-caja/templates/tab-pos-sale.php"

Write-Output "Step 2: Uploading sync script..."
Upload-FtpFile "C:\Users\Usuario\emp-caja\copy_to_demo_full.php" "copy_to_demo_full.php"

Write-Output "Step 3: Triggering sync to demo..."
$res = & curl.exe -k -s "https://empralidad.com.ar/batllie/copy_to_demo_full.php"
Write-Output $res

Write-Output "Step 4: Cleaning up sync script..."
Upload-FtpFile "C:\Users\Usuario\emp-caja\cleanup_remote.php" "cleanup_remote.php"
$cleanRes = & curl.exe -k -s "https://empralidad.com.ar/batllie/cleanup_remote.php"
Write-Output $cleanRes

Write-Output "Sync to Empralidad root and demo completed successfully!"

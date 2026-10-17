$user = "Antigravity@empralidad.com.ar"
$pass = "Actualizando_sitios57"
$baseUri = "ftp://ftp.empralidad.com.ar/"

function Make-FtpDir($remoteDir) {
    $uri = $baseUri + $remoteDir
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
    try {
        $res = $req.GetResponse()
        $res.Close()
        Write-Output "Created remote dir: $remoteDir"
    } catch {
        # Directory might already exist, ignore 550
    }
}

function Upload-FtpFile($localPath, $remoteRelPath) {
    $uri = $baseUri + $remoteRelPath
    $wc = New-Object System.Net.WebClient
    $wc.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
    try {
        $wc.UploadFile($uri, $localPath)
        Write-Output "  [OK] $remoteRelPath"
    } catch {
        Write-Error "Failed uploading ${remoteRelPath}: $($_.Exception.Message)"
    }
}

Write-Output "=== 1. Ensuring remote directories exist ==="
Make-FtpDir "wp-content/plugins/emp-caja/assets/images"
Make-FtpDir "wp-content/plugins/emp-caja/assets/images/experiencia"

Write-Output "=== 2. Uploading Core Files ==="
Upload-FtpFile "C:\Users\Usuario\emp-caja\emp-caja.php" "wp-content/plugins/emp-caja/emp-caja.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-ajax.php" "wp-content/plugins/emp-caja/includes/class-caja-ajax.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-products.php" "wp-content/plugins/emp-caja/includes/class-caja-products.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-experiencia.php" "wp-content/plugins/emp-caja/includes/class-caja-experiencia.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\includes\class-caja-orders.php" "wp-content/plugins/emp-caja/includes/class-caja-orders.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\tab-settings.php" "wp-content/plugins/emp-caja/templates/tab-settings.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\tab-pos-sale.php" "wp-content/plugins/emp-caja/templates/tab-pos-sale.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\templates\dashboard.php" "wp-content/plugins/emp-caja/templates/dashboard.php"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\js\caja-app.js" "wp-content/plugins/emp-caja/assets/js/caja-app.js"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\js\sw.js" "wp-content/plugins/emp-caja/assets/js/sw.js"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\css\caja-cart.css" "wp-content/plugins/emp-caja/assets/css/caja-cart.css"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\css\caja-style.css" "wp-content/plugins/emp-caja/assets/css/caja-style.css"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\images\icon-192.png" "wp-content/plugins/emp-caja/assets/images/icon-192.png"
Upload-FtpFile "C:\Users\Usuario\emp-caja\assets\images\icon-512.png" "wp-content/plugins/emp-caja/assets/images/icon-512.png"
Upload-FtpFile "C:\Users\Usuario\emp-caja\emp-caja.zip" "emp-caja.zip"

Write-Output "=== 3. Uploading Experiencia Images ==="
$imgDir = "C:\Users\Usuario\emp-caja\assets\images\experiencia"
Get-ChildItem -Path $imgDir -File | ForEach-Object {
    $remote = "wp-content/plugins/emp-caja/assets/images/experiencia/" + $_.Name
    Upload-FtpFile $_.FullName $remote
}

Write-Output "=== All files deployed to FTP successfully! ==="

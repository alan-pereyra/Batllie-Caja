$user = "Antigravity@empralidad.com.ar"
$pass = "Actualizando_sitios57"
$baseUri = "ftp://ftp.empralidad.com.ar/"
$localPluginRoot = "C:\Users\Usuario\emp-caja"

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

# Ensure base directories exist
Write-Output "=== 1. Ensuring remote directories exist ==="
$remoteDirs = @(
    "wp-content/plugins/emp-caja",
    "wp-content/plugins/emp-caja/assets",
    "wp-content/plugins/emp-caja/assets/css",
    "wp-content/plugins/emp-caja/assets/js",
    "wp-content/plugins/emp-caja/assets/images",
    "wp-content/plugins/emp-caja/assets/images/experiencia",
    "wp-content/plugins/emp-caja/includes",
    "wp-content/plugins/emp-caja/templates",
    "wp-content/plugins/emp-caja/templates/woocommerce",
    "wp-content/plugins/emp-caja/templates/woocommerce/checkout",
    "wp-content/plugins/emp-caja/templates/woocommerce/single-product",
    "wp-content/plugins/emp-caja/templates/woocommerce/single-product/add-to-cart"
)
foreach ($dir in $remoteDirs) {
    Make-FtpDir $dir
}

Write-Output "=== 2. Uploading Plugin Files ==="
$directoriesToScan = @("includes", "templates", "assets")

# Root plugin files
Upload-FtpFile "$localPluginRoot\emp-caja.php" "wp-content/plugins/emp-caja/emp-caja.php"
Upload-FtpFile "$localPluginRoot\README.md" "wp-content/plugins/emp-caja/README.md"

foreach ($subDir in $directoriesToScan) {
    $files = Get-ChildItem -Path "$localPluginRoot\$subDir" -Recurse -File
    foreach ($f in $files) {
        $relPath = $f.FullName.Substring($localPluginRoot.Length + 1).Replace('\', '/')
        # Ensure parent remote dir exists
        $parentDir = "wp-content/plugins/emp-caja/" + (Split-Path -Path $relPath -Parent).Replace('\', '/')
        Make-FtpDir $parentDir
        
        $remotePath = "wp-content/plugins/emp-caja/" + $relPath
        Upload-FtpFile $f.FullName $remotePath
    }
}

Write-Output "=== 3. Uploading Sync Script to batllie root ==="
Upload-FtpFile "$localPluginRoot\copy_to_demo_full.php" "copy_to_demo_full.php"

Write-Output "=== 4. Triggering Sync to Demo via HTTP ==="
$syncUrl = "https://empralidad.com.ar/batllie/copy_to_demo_full.php"
$res = & curl.exe -k -s $syncUrl
Write-Output $res

Write-Output "=== 5. Cleaning up sync script from server ==="
$delScript = "<?php @unlink('copy_to_demo_full.php'); echo 'Cleaned up'; ?>"
$wc = New-Object System.Net.WebClient
$wc.Credentials = New-Object System.Net.NetworkCredential($user, $pass)
# upload small cleanup
Upload-FtpFile "$localPluginRoot\cleanup_remote.php" "cleanup_remote.php"
& curl.exe -k -s "https://empralidad.com.ar/batllie/cleanup_remote.php" | Out-Null

Write-Output "=== Deployment Complete ==="

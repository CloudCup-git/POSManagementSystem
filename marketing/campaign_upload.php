<?php
/**
 * CloudCup — Upload a new marketing campaign (poster or video).
 */
require __DIR__ . '/config.php';
marketing_require_login();

$activePage = 'upload';
$pageTitle  = 'Marketing — Upload New Ad';

$message = '';
$messageType = '';

$ALLOWED_IMAGE = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$ALLOWED_VIDEO = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
$MAX_BYTES = 100 * 1024 * 1024; // 100MB, mainly for video

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $mediaType   = $_POST['media_type'] ?? '';
    $ctaLabel    = trim($_POST['cta_label'] ?? '') ?: 'Shop Now';
    $ctaUrl      = trim($_POST['cta_url'] ?? '');
    $makeFeatured = isset($_POST['is_featured']);

    $allowedMap = $mediaType === 'video' ? $ALLOWED_VIDEO : $ALLOWED_IMAGE;

    if ($title === '' || !in_array($mediaType, ['poster', 'video'], true)) {
        $message = 'Title and media type are required.';
        $messageType = 'error';
    } elseif (empty($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please choose a file to upload.';
        $messageType = 'error';
    } elseif ($_FILES['media_file']['size'] > $MAX_BYTES) {
        $message = 'File is too large (max 100MB).';
        $messageType = 'error';
    } else {
        $tmpPath  = $_FILES['media_file']['tmp_name'];
        $mimeType = mime_content_type($tmpPath);

        if (!isset($allowedMap[$mimeType])) {
            $message = 'Unsupported file type for a ' . $mediaType . ' (got ' . htmlspecialchars($mimeType) . ').';
            $messageType = 'error';
        } else {
            $ext = $allowedMap[$mimeType];
            $filename = 'campaign_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destDir  = __DIR__ . '/uploads/campaigns/';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $destPath = $destDir . $filename;

            if (!move_uploaded_file($tmpPath, $destPath)) {
                $message = 'Failed to save the uploaded file. Check folder permissions on marketing/uploads/campaigns/.';
                $messageType = 'error';
            } else {
                $relativePath = 'uploads/campaigns/' . $filename;

                // Optional thumbnail (mainly useful for video ads)
                $thumbRelative = null;
                if (!empty($_FILES['thumbnail_file']['tmp_name']) && $_FILES['thumbnail_file']['error'] === UPLOAD_ERR_OK) {
                    $thumbMime = mime_content_type($_FILES['thumbnail_file']['tmp_name']);
                    if (isset($ALLOWED_IMAGE[$thumbMime])) {
                        $thumbName = 'thumb_' . bin2hex(random_bytes(8)) . '.' . $ALLOWED_IMAGE[$thumbMime];
                        move_uploaded_file($_FILES['thumbnail_file']['tmp_name'], $destDir . $thumbName);
                        $thumbRelative = 'uploads/campaigns/' . $thumbName;
                    }
                }

                try {
                    $pdo->beginTransaction();

                    if ($makeFeatured) {
                        $pdo->exec("UPDATE marketing_campaigns SET is_featured = 0");
                    }

                    $stmt = $pdo->prepare(
                        "INSERT INTO marketing_campaigns
                            (title, description, media_type, file_path, thumbnail_path, cta_label, cta_url, is_featured, is_active, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
                    );
                    $stmt->execute([
                        $title, $description ?: null, $mediaType, $relativePath, $thumbRelative,
                        $ctaLabel, $ctaUrl ?: null, $makeFeatured ? 1 : 0, $_SESSION['user_id'] ?? null,
                    ]);

                    $pdo->commit();
                    header('Location: campaign_list.php?uploaded=1');
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Database error: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'error';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Ad — CloudCup Marketing</title>
<link rel="stylesheet" href="css/admin_page.css">
<link rel="stylesheet" href="css/sidebar_admin.css">
<link rel="stylesheet" href="css/marketing.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script src="../js/sidebar-restore.js"></script>

  <?php include __DIR__ . '/includes/marketing_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/marketing_topbar.php'; ?>

    <div class="content">
      <?php if ($message): ?>
        <div class="form-msg <?= $messageType ?>"><?= $message ?></div>
      <?php endif; ?>

      <div class="panel" style="max-width:680px;">
        <div class="panel-title">Upload a New Campaign</div>
        <div class="panel-sub">Posters (JPG/PNG/WebP) or short ad videos (MP4/WebM/MOV, up to 100MB).</div>

        <form class="upload-form" method="post" enctype="multipart/form-data">
          <div class="form-row">
            <label>Campaign Title</label>
            <input type="text" name="title" required maxlength="150" placeholder="e.g. Autumn Pumpkin Spice Launch">
          </div>

          <div class="form-row">
            <label>Description (optional)</label>
            <textarea name="description" maxlength="500" placeholder="Short note shown under the ad, if you want one"></textarea>
          </div>

          <div class="form-row">
            <label>Media Type</label>
            <div class="radio-group">
              <label><input type="radio" name="media_type" value="poster" checked> Poster (image)</label>
              <label><input type="radio" name="media_type" value="video"> Video / Ad</label>
            </div>
          </div>

          <div class="form-row">
            <label>Media File</label>
            <div class="file-drop">
              <input type="file" name="media_file" required accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime">
            </div>
          </div>

          <div class="form-row">
            <label>Thumbnail (optional — recommended for videos)</label>
            <input type="file" name="thumbnail_file" accept="image/jpeg,image/png,image/webp">
          </div>

          <div class="form-row">
            <label>Call-to-Action Button Label</label>
            <input type="text" name="cta_label" maxlength="50" placeholder="Shop Now" value="Shop Now">
          </div>

          <div class="form-row">
            <label>Call-to-Action Link (optional)</label>
            <input type="url" name="cta_url" placeholder="https://... or leave blank to just close the ad">
          </div>

          <div class="form-row">
            <label><input type="checkbox" name="is_featured" style="width:auto;"> Make this the featured ad on the landing page right away</label>
          </div>

          <button type="submit" class="btn-add" style="align-self:flex-start;padding:12px 24px;">Upload Campaign</button>
        </form>
      </div>
    </div>
  </div>

</body>
</html>
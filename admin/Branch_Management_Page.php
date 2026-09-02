<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../auth/Login_Page.php');
    exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/Mapbox_Config.php';
$active_page = 'branches';

if (empty($_SESSION['branch_csrf'])) $_SESSION['branch_csrf'] = bin2hex(random_bytes(32));
$message = '';

function branch_value(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function valid_coordinate(string $value, float $min, float $max): bool {
    return is_numeric($value) && (float)$value >= $min && (float)$value <= $max;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['branch_csrf'], $_POST['csrf'] ?? '')) {
        $message = 'error:Your form has expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['branch_id'] ?? 0);
        if ($action === 'toggle' && $id > 0) {
            $status = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
            $stmt = mysqli_prepare($conn, 'UPDATE branches SET status=? WHERE branch_id=?');
            mysqli_stmt_bind_param($stmt, 'si', $status, $id);
            mysqli_stmt_execute($stmt);
            $message = mysqli_stmt_affected_rows($stmt) >= 0 ? 'success:Branch status updated.' : 'error:Unable to update branch status.';
            mysqli_stmt_close($stmt);
        } elseif (in_array($action, ['create', 'update'], true)) {
            $name = branch_value('branch_name');
            $address = branch_value('address');
            $contact = branch_value('contact_number');
            $hours = branch_value('operating_hours');
            $lat = branch_value('latitude');
            $lng = branch_value('longitude');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($name === '' || $address === '' || $hours === '' || !valid_coordinate($lat, -90, 90) || !valid_coordinate($lng, -180, 180)) {
                $message = 'error:Enter the branch name, address, hours, and valid map coordinates.';
            } else {
                if ($action === 'create') {
                    $stmt = mysqli_prepare($conn, 'INSERT INTO branches (branch_name, address, contact_number, operating_hours, latitude, longitude, status) VALUES (?,?,?,?,?,?,?)');
                    mysqli_stmt_bind_param($stmt, 'ssssdds', $name, $address, $contact, $hours, $lat, $lng, $status);
                } else {
                    $stmt = mysqli_prepare($conn, 'UPDATE branches SET branch_name=?, address=?, contact_number=?, operating_hours=?, latitude=?, longitude=?, status=? WHERE branch_id=?');
                    mysqli_stmt_bind_param($stmt, 'ssssddsi', $name, $address, $contact, $hours, $lat, $lng, $status, $id);
                }
                if ($stmt && mysqli_stmt_execute($stmt)) $message = 'success:Branch ' . ($action === 'create' ? 'added.' : 'updated.');
                else $message = 'error:Could not save the branch. Branch names must be unique.';
                if ($stmt) mysqli_stmt_close($stmt);
            }
        }
    }
}

$branches = [];
$result = mysqli_query($conn, 'SELECT * FROM branches ORDER BY status = \'active\' DESC, branch_name ASC');
if ($result) while ($row = mysqli_fetch_assoc($result)) $branches[] = $row;
$active_count = 0; $inactive_count = 0;
foreach ($branches as $b) { if (($b['status'] ?? '') === 'active') $active_count++; else $inactive_count++; }
$branch_json = json_encode(array_map(static fn($b) => [
    'id' => (int)$b['branch_id'], 'name' => $b['branch_name'], 'address' => $b['address'],
    'lat' => $b['latitude'] === null ? null : (float)$b['latitude'], 'lng' => $b['longitude'] === null ? null : (float)$b['longitude']
], $branches), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Branch Management — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link href="https://api.mapbox.com/mapbox-gl-js/v3.27.0/mapbox-gl.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin_page.css">
  <style>
    .branch-content{margin-left:var(--sidebar-w,240px);flex:1;min-height:100vh;background:var(--cream-light);transition:margin-left .25s ease}
    body.sidebar-hidden .branch-content{margin-left:var(--sidebar-collapsed-w,64px)}

    .branch-topbar{background:var(--white);border-bottom:1px solid rgba(44,92,130,.08);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;position:sticky;top:0;z-index:10}
    .branch-topbar h1{font-family:'Fraunces',serif;font-size:21px;font-weight:700;color:var(--text)}
    .branch-topbar p{font-size:12.5px;color:var(--text-light);margin-top:3px}
    .branch-stats{display:flex;gap:10px}
    .stat-chip{display:flex;align-items:center;gap:7px;background:var(--cream-light);border:1px solid rgba(44,92,130,.08);border-radius:999px;padding:7px 14px 7px 10px;font-size:12.5px;color:var(--text-mid);font-weight:600}
    .stat-chip .dot{width:7px;height:7px;border-radius:50%}
    .stat-chip .dot.active{background:var(--success)}
    .stat-chip .dot.inactive{background:var(--text-light)}
    .stat-chip b{color:var(--text);font-weight:700}

    .branch-body{padding:28px 32px 60px}

    .notice{display:flex;align-items:center;gap:9px;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:13.5px;font-weight:500}
    .notice.success{background:rgba(63,122,79,.08);border:1px solid rgba(63,122,79,.22);color:var(--success)}
    .notice.error{background:rgba(178,59,59,.07);border:1px solid rgba(178,59,59,.22);color:var(--danger)}

    .branch-grid{display:grid;grid-template-columns:minmax(320px,.85fr) minmax(400px,1.15fr);gap:20px;align-items:start}
    @media(max-width:980px){.branch-grid{grid-template-columns:1fr}}

    .panel{background:var(--white);border-radius:14px;border:1px solid rgba(44,92,130,.07);box-shadow:0 1px 2px rgba(11,30,51,.03),0 10px 24px rgba(11,30,51,.04)}
    .panel-head{padding:18px 22px;border-bottom:1px solid rgba(44,92,130,.07);display:flex;justify-content:space-between;align-items:center}
    .panel-head h2{font-family:'Fraunces',serif;font-size:16.5px;font-weight:700;color:var(--text)}
    .panel-head .hint{font-size:11.5px;color:var(--text-light);margin-top:2px}
    .panel-body{padding:22px}

    .form-group{margin-bottom:15px}
    .form-group label{display:block;font-size:11.5px;font-weight:700;color:var(--text-mid);text-transform:uppercase;letter-spacing:.04em;margin-bottom:7px}
    .form-group input,.form-group select,.form-group textarea{box-sizing:border-box;width:100%;border:1.5px solid rgba(44,92,130,.12);border-radius:9px;background:var(--cream-light);padding:10px 12px;font:14px 'Inter',sans-serif;color:var(--text);outline:none;transition:border-color .15s ease,background .15s ease}
    .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--caramel);background:var(--white)}
    .form-group textarea{resize:vertical;min-height:62px}
    .form-group.mono input{font-family:'IBM Plex Mono',monospace;font-size:13px;letter-spacing:.01em}
    .coord-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

    .form-actions{display:flex;gap:9px;margin-top:6px}
    .btn-primary{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--brown-mid);color:var(--white);border:none;border-radius:9px;padding:12px 16px;font:600 13.5px 'Inter',sans-serif;cursor:pointer;transition:background .2s ease}
    .btn-primary:hover{background:var(--caramel)}
    .btn-outline{display:flex;align-items:center;gap:7px;background:var(--white);color:var(--text-mid);border:1.5px solid rgba(44,92,130,.15);border-radius:9px;padding:11px 15px;font:600 13px 'Inter',sans-serif;cursor:pointer;transition:all .2s ease}
    .btn-outline:hover{border-color:var(--caramel);color:var(--caramel)}

    #branch-map{height:322px}
    .map-help{padding:14px 22px;border-top:1px solid rgba(44,92,130,.07);font-size:11.5px;color:var(--text-light);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .map-help .coords{font-family:'IBM Plex Mono',monospace;color:var(--text-mid);font-size:11.5px}

    .branch-list{margin-top:20px}
    table{width:100%;border-collapse:collapse}
    thead{background:var(--cream-light)}
    thead th{text-align:left;padding:12px 20px;font-size:10.5px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid rgba(44,92,130,.08);white-space:nowrap}
    tbody td{padding:15px 20px;border-bottom:1px solid rgba(44,92,130,.05);font-size:13.5px;color:var(--text);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    tbody tr:hover{background:rgba(98,142,144,.045)}
    .branch-name{font-family:'Fraunces',serif;font-weight:700;color:var(--text);font-size:14.5px}
    .addr-cell small{color:var(--text-light);font-size:12px}
    .contact-cell{font-family:'IBM Plex Mono',monospace;color:var(--text-mid);font-size:12.5px}

    .status{display:inline-flex;align-items:center;gap:6px;padding:5px 12px 5px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
    .status::before{content:"";width:6px;height:6px;border-radius:50%}
    .status.active{background:rgba(63,122,79,.1);color:var(--success)}
    .status.active::before{background:var(--success)}
    .status.inactive{background:rgba(122,107,96,.12);color:var(--text-light)}
    .status.inactive::before{background:var(--text-light)}

    .actions{display:flex;gap:7px}
    .actions button{font:600 12px 'Inter',sans-serif;padding:7px 12px;border-radius:7px;cursor:pointer}
    .edit-btn{background:var(--white);border:1.5px solid rgba(44,92,130,.15);color:var(--text-mid)}
    .edit-btn:hover{border-color:var(--caramel);color:var(--caramel)}
    .status-btn{border:1.5px solid rgba(178,59,59,.25);background:rgba(178,59,59,.05);color:var(--danger)}
    .status-btn.activate{border-color:rgba(63,122,79,.3);background:rgba(63,122,79,.06);color:var(--success)}
    .status-btn:hover{filter:brightness(.95)}
    .empty-row td{text-align:center;color:var(--text-light);padding:34px 20px;font-size:13.5px}

    .cafe-map-marker{align-items:center;background:#fff;border:0;border-radius:8px;box-shadow:0 3px 10px rgba(0,0,0,.28);color:var(--brown-dark);display:flex;height:50px;justify-content:center;padding:3px;width:50px}
    .cafe-map-marker svg{display:block;height:100%;width:100%}

    @media(max-width:900px){.branch-body{padding:20px}.branch-topbar{padding:16px 20px}}
  </style>
</head><body>
<script src="../js/sidebar-toggle.js"></script><?php require __DIR__ . '/Sidebar_Admin.php'; ?>
<main class="branch-content">
  <header class="branch-topbar">
    <div>
      <h1>Branch Management</h1>
      <p>Add and maintain Cloud Cup branch details and map locations.</p>
    </div>
    <div class="branch-stats">
      <span class="stat-chip"><span class="dot active"></span><b><?= $active_count ?></b>&nbsp;active</span>
      <span class="stat-chip"><span class="dot inactive"></span><b><?= $inactive_count ?></b>&nbsp;inactive</span>
    </div>
  </header>

  <div class="branch-body">
    <?php if ($message): [$kind, $text] = explode(':', $message, 2); ?><div class="notice <?= $kind ?>"><?= htmlspecialchars($text) ?></div><?php endif; ?>

    <div class="branch-grid">
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2 id="form-title">Add branch</h2>
            <div class="hint">Saved branches appear in the list below.</div>
          </div>
        </div>
        <div class="panel-body">
          <form method="post" id="branch-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['branch_csrf']) ?>"><input type="hidden" name="action" id="form-action" value="create"><input type="hidden" name="branch_id" id="branch-id">
            <div class="form-group"><label>Branch name</label><input required maxlength="100" name="branch_name" id="branch-name" placeholder="e.g. Makati Branch"></div>
            <div class="form-group"><label>Full address</label><textarea required maxlength="255" name="address" id="branch-address" placeholder="Street, barangay, city"></textarea></div>
            <div class="form-group mono"><label>Contact number</label><input maxlength="30" name="contact_number" id="branch-contact" placeholder="e.g. 0917 123 4567"></div>
            <div class="form-group"><label>Operating hours</label><input required maxlength="120" name="operating_hours" id="branch-hours" placeholder="e.g. Mon–Sun, 8:00 AM–9:00 PM"></div>
            <div class="form-group coord-row">
              <div class="mono"><label>Latitude</label><input required type="number" step="any" min="-90" max="90" name="latitude" id="branch-lat"></div>
              <div class="mono"><label>Longitude</label><input required type="number" step="any" min="-180" max="180" name="longitude" id="branch-lng"></div>
            </div>
            <div class="form-group"><label>Status</label><select name="status" id="branch-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            <div class="form-actions">
              <button class="btn-primary" type="submit" id="save-btn">Save branch</button>
              <button class="btn-outline" type="button" id="cancel-edit" hidden>Cancel</button>
            </div>
          </form>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Pin branch location</h2>
            <div class="hint">Click the map to set coordinates.</div>
          </div>
        </div>
        <div id="branch-map"></div>
        <div class="map-help">
          <span>Existing branches are shown as markers.</span>
          <span class="coords" id="coord-readout">— , —</span>
        </div>
      </section>
    </div>

    <section class="panel branch-list">
      <div class="panel-head"><h2>All branches</h2></div>
      <table>
        <thead><tr><th>Branch</th><th>Address / hours</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$branches): ?><tr class="empty-row"><td colspan="5">No branches have been added.</td></tr><?php endif; foreach ($branches as $b): ?>
          <tr>
            <td class="branch-name"><?= htmlspecialchars($b['branch_name']) ?></td>
            <td class="addr-cell"><?= htmlspecialchars($b['address'] ?: 'No address') ?><br><small><?= htmlspecialchars($b['operating_hours'] ?: 'No hours set') ?></small></td>
            <td class="contact-cell"><?= htmlspecialchars($b['contact_number'] ?: '—') ?></td>
            <td><span class="status <?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
            <td>
              <div class="actions">
                <button type="button" class="edit-btn" data-branch='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>Edit</button>
                <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['branch_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="branch_id" value="<?= (int)$b['branch_id'] ?>"><input type="hidden" name="new_status" value="<?= $b['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="status-btn<?= $b['status'] === 'active' ? '' : ' activate' ?>" type="submit"><?= $b['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>
</main>
<script src="https://api.mapbox.com/mapbox-gl-js/v3.27.0/mapbox-gl.js"></script>
<script>
const branches = <?= $branch_json ?>, lat = document.getElementById('branch-lat'), lng = document.getElementById('branch-lng'), coordReadout = document.getElementById('coord-readout'); let selection;
mapboxgl.accessToken = <?= json_encode(MAPBOX_PUBLIC_TOKEN) ?>;
const map = new mapboxgl.Map({container:'branch-map',style:'mapbox://styles/mapbox/streets-v12',center:[120.9842,14.5995],zoom:10});
map.addControl(new mapboxgl.NavigationControl(),'top-right');
const cafeMarker=()=>{const el=document.createElement('div');el.className='cafe-map-marker';el.innerHTML='<svg viewBox="0 0 120 116" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.5"><rect x="10" y="30" width="100" height="10" rx="5" fill="white"/><circle cx="60" cy="21" r="15" fill="white"/><g transform="translate(60 21) rotate(28)" stroke-width="3.6"><ellipse rx="7" ry="10.5"/><path d="M0 -8.5 C3.2 -3 -3.2 3 0 8.5"/></g><path d="M10 41 a10 10 0 0 0 20 0"/><path d="M30 41 a10 10 0 0 0 20 0"/><path d="M50 41 a10 10 0 0 0 20 0"/><path d="M70 41 a10 10 0 0 0 20 0"/><path d="M90 41 a10 10 0 0 0 20 0"/><line x1="10" y1="41" x2="8.5" y2="58"/><line x1="30" y1="41" x2="29.3" y2="58"/><line x1="50" y1="41" x2="49.7" y2="58"/><line x1="70" y1="41" x2="70.3" y2="58"/><line x1="90" y1="41" x2="90.7" y2="58"/><line x1="110" y1="41" x2="111.5" y2="58"/><rect x="14" y="58" width="92" height="52" rx="2"/><rect x="21" y="64" width="44" height="30" rx="2"/><circle cx="38" cy="69" r="1.5" fill="currentColor"/><circle cx="43" cy="69" r="1.5" fill="currentColor"/><circle cx="48" cy="69" r="1.5" fill="currentColor"/><path d="M31 77 h14 v7.5 a7 7 0 0 1 -14 0 z"/><path d="M45 79 h3.8 a2.8 2.8 0 0 1 0 4.6 h-3.8"/><rect x="24" y="100" width="14" height="5" rx="1.5"/><rect x="42" y="100" width="18" height="5" rx="1.5"/><rect x="71" y="64" width="32" height="44"/><line x1="87" y1="64" x2="87" y2="108"/><line x1="71" y1="89" x2="103" y2="89"/><line x1="79" y1="71" x2="79" y2="79"/><line x1="95" y1="71" x2="95" y2="79"/></g></svg>';return el;};
function selectLocation(a,b){lat.value=Number(a).toFixed(6);lng.value=Number(b).toFixed(6);coordReadout.textContent=Number(a).toFixed(4)+'° N, '+Number(b).toFixed(4)+'° E';if(selection)selection.setLngLat([b,a]);else{selection=new mapboxgl.Marker({element:cafeMarker(),anchor:'bottom',draggable:true}).setLngLat([b,a]).addTo(map);selection.on('dragend',()=>{const p=selection.getLngLat();selectLocation(p.lat,p.lng);});}}
map.on('click',e=>selectLocation(e.lngLat.lat,e.lngLat.lng));
const bounds=new mapboxgl.LngLatBounds();branches.forEach(b=>{if(b.lat!==null&&b.lng!==null){new mapboxgl.Marker({element:cafeMarker(),anchor:'bottom'}).setLngLat([b.lng,b.lat]).setPopup(new mapboxgl.Popup({offset:24}).setHTML('<strong>'+b.name.replace(/</g,'&lt;')+'</strong><br>'+b.address.replace(/</g,'&lt;'))).addTo(map);bounds.extend([b.lng,b.lat]);}});if(!bounds.isEmpty())map.fitBounds(bounds,{padding:35,maxZoom:14});
document.querySelectorAll('.edit-btn').forEach(btn=>btn.addEventListener('click',()=>{const b=JSON.parse(btn.dataset.branch);document.getElementById('form-title').textContent='Edit branch';document.getElementById('form-action').value='update';document.getElementById('save-btn').textContent='Update branch';document.getElementById('cancel-edit').hidden=false;document.getElementById('branch-id').value=b.branch_id;document.getElementById('branch-name').value=b.branch_name;document.getElementById('branch-address').value=b.address||'';document.getElementById('branch-contact').value=b.contact_number||'';document.getElementById('branch-hours').value=b.operating_hours||'';document.getElementById('branch-status').value=b.status;if(b.latitude&&b.longitude){selectLocation(b.latitude,b.longitude);map.flyTo({center:[b.longitude,b.latitude],zoom:15})}window.scrollTo({top:0,behavior:'smooth'});}));
document.getElementById('cancel-edit').addEventListener('click',()=>location.href='Branch_Management_Page.php');
</script></body></html>

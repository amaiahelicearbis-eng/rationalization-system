<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_admin();

$error=''; $notice='';
$processOptions=[];
foreach (PROCESS_LIST as $key => $process) { $processOptions[$key] = (string)$process['label']; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'');
    try {
        if ($action==='create_user') {
            $username=trim((string)($_POST['username']??''));
            $full=trim((string)($_POST['full_name']??''));
            $password=(string)($_POST['password']??'');
            $role=(string)($_POST['role']??'user');
            $scopes=array_values(array_intersect(array_keys($processOptions), (array)($_POST['user_process']??[])));
            if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/',$username)) throw new RuntimeException('ID Number must be 3–80 characters and use letters, numbers, dots, underscores, or hyphens.');
            if ($full==='') throw new RuntimeException('Full name is required.');
            if (!in_array($role,['admin','user'],true)) $role='user';
            if ($role==='admin') $scopes=[]; // Administrators automatically have access to every process.
            if ($role==='user' && !$scopes) throw new RuntimeException('Select at least one process for this user.');
            $scopeValue=implode(',', $scopes);
            $stmt=db()->prepare('INSERT INTO dbo.users(username,password_hash,full_name,role,user_process,is_active) OUTPUT INSERTED.id VALUES(?,?,?,?,?,1)');
            $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$full,$role,$scopeValue]);
            $id=(int)$stmt->fetchColumn();
            audit_log('CREATE','user',$id,'Created user '.$username,null,['username'=>$username,'full_name'=>$full,'role'=>$role,'user_process'=>$scopeValue,'is_active'=>1]);
            $notice='User created successfully.';
        } elseif ($action==='update_user') {
            $id=(int)($_POST['id']??0);
            $stmt=db()->prepare('SELECT id,username,full_name,role,user_process,is_active FROM dbo.users WHERE id=?');
            $stmt->execute([$id]); $before=$stmt->fetch();
            if (!$before) throw new RuntimeException('User not found.');
            $username=trim((string)($_POST['username']??''));
            $full=trim((string)($_POST['full_name']??''));
            $password=(string)($_POST['password']??'');
            $role=(string)($_POST['role']??'user');
            $scopes=array_values(array_intersect(array_keys($processOptions), (array)($_POST['user_process']??[])));
            if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/',$username)) throw new RuntimeException('ID Number must be 3–80 characters and use letters, numbers, dots, underscores, or hyphens.');
            if ($full==='') throw new RuntimeException('Full name is required.');
            if (!in_array($role,['admin','user'],true)) throw new RuntimeException('Select a valid role.');
            if ($role==='admin') $scopes=[];
            if ($role==='user' && !$scopes) throw new RuntimeException('Select at least one process for this user.');
            $scopeValue=implode(',', $scopes);
            if ($password!=='') {
                db()->prepare('UPDATE dbo.users SET username=?,full_name=?,role=?,user_process=?,password_hash=?,updated_at=SYSDATETIME() WHERE id=?')
                    ->execute([$username,$full,$role,$scopeValue,password_hash($password,PASSWORD_DEFAULT),$id]);
            } else {
                db()->prepare('UPDATE dbo.users SET username=?,full_name=?,role=?,user_process=?,updated_at=SYSDATETIME() WHERE id=?')
                    ->execute([$username,$full,$role,$scopeValue,$id]);
            }
            $after=['username'=>$username,'full_name'=>$full,'role'=>$role,'user_process'=>$scopeValue,'is_active'=>$before['is_active']];
            audit_log('UPDATE','user',$id,'Updated user account '.$username,$before,$after);
            $notice='User account updated successfully.';
        } elseif ($action==='delete_user') {
            $id=(int)($_POST['id']??0);
            if ($id===(int)current_user()['id']) throw new RuntimeException('You cannot delete your own account.');
            $stmt=db()->prepare('SELECT id,username,full_name,role,user_process,is_active FROM dbo.users WHERE id=?');
            $stmt->execute([$id]); $before=$stmt->fetch();
            if (!$before) throw new RuntimeException('User not found.');
            db()->prepare('DELETE FROM dbo.users WHERE id=?')->execute([$id]);
            audit_log('DELETE','user',$id,'Deleted user '.$before['username'],$before,null);
            $notice='User account deleted.';
        } elseif ($action==='update_process') {
            $id=(int)($_POST['id']??0);
            $stmt=db()->prepare('SELECT id,username,role,user_process FROM dbo.users WHERE id=?');
            $stmt->execute([$id]); $before=$stmt->fetch();
            if (!$before) throw new RuntimeException('User not found.');
            if ($before['role']!=='user') throw new RuntimeException('Administrator accounts already have access to all processes.');
            $scopes=array_values(array_intersect(array_keys($processOptions), (array)($_POST['user_process']??[])));
            if (!$scopes) throw new RuntimeException('Select at least one process for this user.');
            $scopeValue=implode(',', $scopes);
            db()->prepare('UPDATE dbo.users SET user_process=?,updated_at=SYSDATETIME() WHERE id=?')->execute([$scopeValue,$id]);
            $after=$before; $after['user_process']=$scopeValue;
            audit_log('UPDATE','user',$id,'Updated process access for '.$before['username'],$before,$after);
            $notice='User process access updated.';
        } elseif ($action==='toggle_user') {
            $id=(int)($_POST['id']??0);
            if ($id===(int)current_user()['id']) throw new RuntimeException('You cannot deactivate your own account.');
            $stmt=db()->prepare('SELECT id,username,full_name,role,is_active FROM dbo.users WHERE id=?');
            $stmt->execute([$id]); $before=$stmt->fetch();
            if (!$before) throw new RuntimeException('User not found.');
            $new=(int)!((int)$before['is_active']);
            db()->prepare('UPDATE dbo.users SET is_active=?,updated_at=SYSDATETIME() WHERE id=?')->execute([$new,$id]);
            $after=$before; $after['is_active']=$new;
            audit_log($new?'ACTIVATE':'DEACTIVATE','user',$id,($new?'Activated':'Deactivated').' user '.$before['username'],$before,$after);
            $notice='User status updated.';
        }
    } catch(Throwable $e) {
        $error=$e instanceof PDOException && str_contains(strtolower($e->getMessage()),'duplicate')?'That ID Number already exists.':$e->getMessage();
    }
}
$users=db()->query('SELECT id,username,full_name,role,user_process,is_active,created_at FROM dbo.users ORDER BY id')->fetchAll();
$pageTitle='User Management'; $activePage='users'; require __DIR__.'/includes/header.php';
?>
<section class="content">
<div class="page-intro"><div><h2>Manage users</h2><p>Assign each standard user access to one or more production processes. Administrators automatically have access to all processes.</p></div></div>
<?php if($notice):?><div class="notice notice-success"><strong>Success</strong><span><?=h($notice)?></span></div><?php endif;?>
<?php if($error):?><div class="notice notice-error"><strong>Could not complete action</strong><span><?=h($error)?></span></div><?php endif;?>
<section class="panel"><div class="panel-head"><div><h3>Create user</h3><p>Passwords are stored as secure hashes.</p></div></div>
<form method="post" class="user-create-form"><?=csrf_field()?><input type="hidden" name="action" value="create_user">
<label class="field"><span>Full name</span><input name="full_name" required></label>
<label class="field"><span>ID Number</span><input name="username" minlength="3" maxlength="80" required></label>
<label class="field"><span>Temporary password</span><input type="password" name="password" required></label>
<label class="field"><span>Role</span><select name="role"><option value="user">User</option><option value="admin">Administrator</option></select></label>
<fieldset class="field process-access-list" id="createProcessAccess"><legend>Process access</legend><div class="process-options-row"><?php foreach($processOptions as $value=>$label):?><label><input type="checkbox" name="user_process[]" value="<?=h($value)?>"> <span><?=h($label)?></span></label><?php endforeach;?></div></fieldset>
<button class="btn btn-primary create-user-submit" type="submit">Create account</button></form></section>
<script>
(() => {
  const role = document.querySelector('select[name="role"]');
  const access = document.getElementById('createProcessAccess');
  if (!role || !access) return;
  const syncProcessAccess = () => {
    const isAdmin = role.value === 'admin';
    access.style.display = isAdmin ? 'none' : '';
    access.hidden = isAdmin;
    access.querySelectorAll('input[type="checkbox"]').forEach(input => {
      input.disabled = isAdmin;
      if (isAdmin) input.checked = false;
    });
  };
  role.addEventListener('change', syncProcessAccess);
  syncProcessAccess();
})();
</script>
<section class="panel data-panel"><div class="panel-head"><div><h3>Existing accounts</h3><p><?=count($users)?> user account(s)</p></div></div>
<div class="table-wrap"><table class="admin-table"><thead><tr><th>ID</th><th>Full name</th><th>ID Number</th><th>Role</th><th>Process access</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach($users as $u): $assigned=array_filter(array_map('trim',explode(',',(string)$u['user_process']))); if(in_array('subassy',$assigned,true)){$assigned[]='saki';$assigned[]='ato';} ?>
<tr><td><?=h($u['id'])?></td><td><?=h($u['full_name'])?></td><td><?=h($u['username'])?></td><td><?=h(ucfirst($u['role']))?></td>
<td><?php if($u['role']==='admin'):?><span class="muted">All processes</span><?php else:?><div class="assigned-processes"><?php $shown=0; foreach($processOptions as $value=>$label): if(in_array($value,$assigned,true)): $shown++;?><span class="process-chip"><?=h($label)?></span><?php endif; endforeach; if(!$shown):?><span class="muted">No process assigned</span><?php endif;?></div><?php endif;?></td>
<td><span class="action-pill <?=$u['is_active']?'action-activate':'action-deactivate'?>"><?=$u['is_active']?'Active':'Inactive'?></span></td><td><?=h($u['created_at'])?></td>
<td class="user-actions"><button type="button" class="btn btn-secondary btn-small edit-user-btn" data-id="<?=h($u['id'])?>" data-name="<?=h($u['full_name'])?>" data-username="<?=h($u['username'])?>" data-role="<?=h($u['role'])?>" data-process="<?=h((string)$u['user_process'])?>">Edit</button>
<?php if((int)$u['id']!==(int)current_user()['id']):?><form method="post" onsubmit="return confirm('<?=$u['is_active']?'Deactivate':'Activate'?> this account?')"><?=csrf_field()?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn btn-secondary btn-small" type="submit"><?=$u['is_active']?'Deactivate':'Activate'?></button></form>
<form method="post" onsubmit="return confirm('Permanently delete this user account? This cannot be undone.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form><?php else:?><span class="muted">Current account</span><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div></section></section>
<dialog id="editUserModal" class="user-edit-modal">
<form method="post" id="editUserForm" class="user-edit-form" novalidate><?=csrf_field()?><input type="hidden" name="action" value="update_user"><input type="hidden" name="id" id="editUserId">
<div class="modal-heading"><div><h3>Edit user</h3><p>Update account details and process permissions.</p></div><button type="button" class="btn btn-secondary btn-small" id="closeEditUser">Close</button></div>
<div class="user-edit-grid">
<label class="field"><span>Full name</span><input name="full_name" id="editFullName" required></label>
<label class="field"><span>ID Number</span><input name="username" id="editUsername" minlength="3" maxlength="80" required></label>
<label class="field"><span>New password</span><input type="password" name="password" id="editPassword" autocomplete="new-password" placeholder="Leave blank to keep current password"></label>
<label class="field"><span>Role</span><select name="role" id="editRole"><option value="user">User</option><option value="admin">Administrator</option></select></label>
<fieldset class="field process-access-list" id="editProcessAccess"><legend>Process access</legend><?php foreach($processOptions as $value=>$label):?><label><input type="checkbox" name="user_process[]" value="<?=h($value)?>"> <?=h($label)?></label><?php endforeach;?></fieldset>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" id="cancelEditUser">Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
</form></dialog>
<style>

.user-create-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 13px;padding:0 22px 22px;align-items:end}
.user-create-form>.field{min-width:0}
.user-create-form>.field input,.user-create-form>.field select{width:100%;height:40px;box-sizing:border-box;border:1px solid #cfd9e5;border-radius:8px;padding:0 11px;background:#fff;color:#263329;font-size:13px}
.user-create-form>.process-access-list{grid-column:1/-1;display:block;min-width:0;margin:2px 0 0;padding:12px 14px 14px;border:1px solid #d9e2d6;border-radius:9px;background:#f8faf7}
.user-create-form>.process-access-list[hidden]{display:none!important}
.process-options-row{display:flex;flex-direction:row;align-items:center;flex-wrap:wrap;gap:10px 22px}
.process-options-row label{display:inline-flex;flex-direction:row;align-items:center;gap:7px;white-space:nowrap;font-size:12px;font-weight:500;color:#34463b}
.process-options-row input{margin:0;width:15px;height:15px;accent-color:#789668}
.create-user-submit{grid-column:1/-1;justify-self:start;min-width:180px}
.assigned-processes{display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-width:170px}
.process-chip{display:inline-flex;align-items:center;padding:4px 8px;border:1px solid #d8e4d4;border-radius:999px;background:#f1f6ee;color:#47633e;font-size:11px;line-height:1.35;white-space:nowrap}
@media(max-width:900px){.user-create-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.user-create-form{grid-template-columns:1fr;padding:0 15px 17px}.user-create-form>.process-access-list{grid-column:1}.process-options-row{gap:10px 16px}}

.user-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.user-actions form{margin:0}.btn-danger{background:#b42318;color:#fff;border:0}.user-edit-modal{width:min(720px,calc(100% - 28px));border:0;border-radius:14px;padding:0;box-shadow:0 20px 60px #0004}.user-edit-modal::backdrop{background:#14202bcc}.user-edit-form{padding:24px}.modal-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px}.modal-heading h3{margin:0 0 5px}.modal-heading p{margin:0;color:#64748b}.user-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.user-edit-grid .field{min-width:0}.user-edit-grid input:not([type=checkbox]),.user-edit-grid select{width:100%;box-sizing:border-box}.user-edit-grid fieldset{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px 22px}.process-access-list label{display:flex;align-items:center;gap:8px}.process-access-list input[type=checkbox]{width:auto}.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}@media(max-width:600px){.user-edit-grid{grid-template-columns:1fr}.user-edit-grid fieldset{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<script>
(() => {
 const modal=document.getElementById('editUserModal');
 const role=document.getElementById('editRole');
 const access=document.getElementById('editProcessAccess');
 const sync=()=>{const admin=role.value==='admin';access.hidden=admin;access.style.display=admin?'none':'';access.querySelectorAll('input[type=checkbox]').forEach(c=>{c.disabled=admin;if(admin)c.checked=false;});};
 document.querySelectorAll('.edit-user-btn').forEach(btn=>btn.addEventListener('click',()=>{
  document.getElementById('editUserId').value=btn.dataset.id;
  document.getElementById('editFullName').value=btn.dataset.name;
  document.getElementById('editUsername').value=btn.dataset.username;
  document.getElementById('editRole').value=btn.dataset.role;
  document.getElementById('editPassword').value='';
  const selected=(btn.dataset.process||'').split(',').map(v=>v.trim());
  access.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=selected.includes(c.value));
  sync();modal.showModal();
 }));
 role.addEventListener('change',sync);
 ['closeEditUser','cancelEditUser'].forEach(id=>document.getElementById(id).addEventListener('click',()=>modal.close()));
 modal.addEventListener('click',e=>{if(e.target===modal)modal.close();});
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>

<section class="page-section"><div class="container narrow"><div class="row"><div class="col-12">
<h1><?= e(__('Host requests')) ?></h1>
<?php if(!$requests): ?><p><?= e(__('No host requests.')) ?></p><?php endif; ?>
<?php foreach($requests as $request): ?>
<article class="surface-card mb-3">
<h2><?= e($request['full_name']) ?></h2><p><?= e($request['email']) ?> · <?= e($request['phone']) ?></p>
<p><span class="status status-<?= e($request['status']) ?>"><?= e(status_label($request['status'])) ?></span> <?= e($request['created_at']) ?></p>
<?php if($request['is_debug']): ?><p class="alert alert-warning"><?= e(__('Local debug verification — not real identity verification.')) ?></p><?php endif; ?>
<?php if($request['reason']!==''): ?><p><?= e($request['reason']) ?></p><?php endif; ?>
<?php if($request['status']==='pending'): ?>
<form method="post" action="<?= e(url('/admin/host-requests/'.(int)$request['user_id'].'/decide')) ?>"><?= csrf_field() ?>
<label for="host-reason-<?= (int)$request['user_id'] ?>"><?= e(__('Reason (required for rejection)')) ?></label>
<input class="form-control mb-3" id="host-reason-<?= (int)$request['user_id'] ?>" name="reason" maxlength="1000">
<div class="action-row"><button class="btn btn-primary" name="decision" value="approved"><?= e(__('Approve host access')) ?></button><button class="btn btn-outline-danger" name="decision" value="rejected"><?= e(__('Reject request')) ?></button></div>
</form><?php endif; ?>
</article><?php endforeach; ?>
<?php $pageBase='/admin/host-requests'; $pageQuery=[]; $pageLabel=__('Host requests'); require dirname(__DIR__).'/articles/pagination.php'; ?>
</div></div></div></section>

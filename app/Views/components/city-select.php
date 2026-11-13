<?php
/** $cityValue: selected form value; $existingCity: persisted value, never old POST data. */
$selectedCity=\App\Services\ListingCity::canonical($cityValue)??$cityValue;
$legacyCity=$existingCity!=='' && \App\Services\ListingCity::canonical($existingCity)===null?$existingCity:null;
?>
<select class="form-control" id="city" name="city" required>
    <option value=""><?= e(__('Chọn tỉnh / thành phố')) ?></option>
    <?php if($legacyCity!==null): ?><option value="<?= e($legacyCity) ?>" <?= $selectedCity===$legacyCity?'selected':'' ?>><?= e($legacyCity) ?></option><?php endif; ?>
    <?php foreach(\App\Services\ListingCity::groups() as $group=>$cities): ?>
    <optgroup label="<?= e(__($group)) ?>">
        <?php foreach($cities as $city): ?><option value="<?= e($city) ?>" <?= $selectedCity===$city?'selected':'' ?>><?= e($city) ?></option><?php endforeach; ?>
    </optgroup>
    <?php endforeach; ?>
</select>

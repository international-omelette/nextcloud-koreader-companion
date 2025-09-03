<?php
script('koreader_companion', 'admin');
style('koreader_companion', 'settings');

/** @var \OCP\IL10N $l */
/** @var array $_ */

?>
<div id="koreader_companion_settings" class="section">
	<h2><?php p($l->t('KOReader Companion')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Configure the KOReader Companion app')); ?></p>

	<div class="setting">
		<label for="koreader_companion_folder"><?php p($l->t('eBooks Folder')); ?></label>
		<input type="text" id="koreader_companion_folder" name="koreader_companion_folder" value="<?php p($_['folder']); ?>" />
		<p class="settings-hint"><?php p($l->t('The name of the folder where your eBooks are stored.')); ?></p>
	</div>

	<div class="setting">
		<input type="checkbox" id="koreader_companion_restrict_uploads" name="koreader_companion_restrict_uploads" class="checkbox" 
			   <?php p($_['restrict_uploads'] === 'yes' ? 'checked="checked"' : ''); ?> />
		<label for="koreader_companion_restrict_uploads"><?php p($l->t('Restrict uploads to app interface only')); ?></label>
		<p class="settings-hint"><?php p($l->t('When enabled, only books uploaded through the app will be processed. Direct file uploads to the Books folder will be ignored or moved to quarantine.')); ?></p>
	</div>

	<div class="setting">
		<input type="checkbox" id="koreader_companion_auto_cleanup" name="koreader_companion_auto_cleanup" class="checkbox" 
			   <?php p($_['auto_cleanup'] === 'yes' ? 'checked="checked"' : ''); ?> />
		<label for="koreader_companion_auto_cleanup"><?php p($l->t('Auto-cleanup unprocessed files')); ?></label>
		<p class="settings-hint"><?php p($l->t('Automatically move files uploaded outside the app to a quarantine folder to keep metadata consistent.')); ?></p>
	</div>

	<div class="setting">
		<input type="checkbox" id="koreader_companion_auto_rename" name="koreader_companion_auto_rename" class="checkbox" 
			   <?php p($_['auto_rename'] === 'yes' ? 'checked="checked"' : ''); ?> />
		<label for="koreader_companion_auto_rename"><?php p($l->t('Auto-rename files based on metadata')); ?></label>
		<p class="settings-hint"><?php p($l->t('Automatically rename uploaded files using the format: "Author - Title (Year).ext" for consistent naming.')); ?></p>
	</div>
</div>

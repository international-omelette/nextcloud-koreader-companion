document.addEventListener('DOMContentLoaded', function() {
    const folderInput = document.getElementById('koreader_companion_folder');
    const restrictUploadsCheckbox = document.getElementById('koreader_companion_restrict_uploads');
    const autoCleanupCheckbox = document.getElementById('koreader_companion_auto_cleanup');
    const autoRenameCheckbox = document.getElementById('koreader_companion_auto_rename');
    
    // Folder input handler
    if (folderInput) {
        folderInput.addEventListener('blur', function() {
            const value = this.value;
            
            // Show loading state
            this.style.backgroundColor = '#fff3cd';
            
            // Save the setting
            const url = OC.generateUrl('/apps/koreader_companion/settings/folder');
            fetch(url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    folder: value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Success feedback
                    this.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        this.style.backgroundColor = '';
                    }, 2000);
                } else {
                    // Error feedback
                    this.style.backgroundColor = '#f8d7da';
                    console.error('Error saving setting:', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                this.style.backgroundColor = '#f8d7da';
            });
        });
    }

    // Restrict uploads checkbox handler
    if (restrictUploadsCheckbox) {
        restrictUploadsCheckbox.addEventListener('change', function() {
            const value = this.checked ? 'yes' : 'no';
            saveBooleanSetting('restrict_uploads', value, this);
        });
    }

    // Auto cleanup checkbox handler
    if (autoCleanupCheckbox) {
        autoCleanupCheckbox.addEventListener('change', function() {
            const value = this.checked ? 'yes' : 'no';
            saveBooleanSetting('auto_cleanup', value, this);
        });
    }

    // Auto rename checkbox handler
    if (autoRenameCheckbox) {
        autoRenameCheckbox.addEventListener('change', function() {
            const value = this.checked ? 'yes' : 'no';
            saveBooleanSetting('auto_rename', value, this);
        });
    }

    function saveBooleanSetting(setting, value, element) {
        // Show loading state
        element.style.opacity = '0.5';
        
        // Save the setting
        const url = OC.generateUrl('/apps/koreader_companion/settings/' + setting);
        fetch(url, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                value: value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Success feedback
                element.style.opacity = '1';
                // Show success notification
                if (window.OC && window.OC.Notification) {
                    OC.Notification.showTemporary(t('koreader_companion', 'Setting saved successfully'));
                }
            } else {
                // Error feedback
                element.style.opacity = '1';
                console.error('Error saving setting:', data.message);
                if (window.OC && window.OC.Notification) {
                    OC.Notification.showTemporary(t('koreader_companion', 'Error saving setting: ' + data.message));
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            element.style.opacity = '1';
            if (window.OC && window.OC.Notification) {
                OC.Notification.showTemporary(t('koreader_companion', 'Network error saving setting'));
            }
        });
    }
});
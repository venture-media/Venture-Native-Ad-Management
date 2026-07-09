/**
 * Venture Native Ad Management - Admin JavaScript
 * Version: 0.9.1
 */

(function() {
    'use strict';

    // ====================== CLIENTS ======================
    window.ventureShowClientModal = function() {
        const modal = document.getElementById('venture-client-modal');
        if (!modal) return;
        document.getElementById('modal-title').textContent = 'Add New Client';
        document.getElementById('client-id').value = '';
        document.getElementById('client-name').value = '';
        modal.style.display = 'block';
    };

    window.ventureHideClientModal = function() {
        const modal = document.getElementById('venture-client-modal');
        if (modal) modal.style.display = 'none';
    };

    window.ventureEditClient = function(id, name) {
        const modal = document.getElementById('venture-client-modal');
        if (!modal) return;
        document.getElementById('modal-title').textContent = 'Edit Client';
        document.getElementById('client-id').value = id;
        document.getElementById('client-name').value = name || '';
        modal.style.display = 'block';
    };

    window.ventureSaveClient = function() {
        const idEl = document.getElementById('client-id');
        const nameEl = document.getElementById('client-name');
        if (!idEl || !nameEl) return;

        const id = idEl.value;
        const name = nameEl.value.trim();
        if (!name) return alert('Name is required');

        const data = new FormData();
        data.append('action', 'venture_save_client');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('id', id);
        data.append('name', name);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message || 'Error saving client');
                }
            })
            .catch(() => alert('Network error'));
    };

    window.ventureDeleteClient = function(id) {
        if (!confirm('Delete this client and all its ads?')) return;

        const data = new FormData();
        data.append('action', 'venture_delete_client');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('id', id);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) location.reload();
                else alert('Error deleting client');
            })
            .catch(() => alert('Network error'));
    };

    // ====================== ADVERTISEMENTS ======================
    let mediaUploader = null;

    window.ventureSelectImage = function() {
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        if (typeof wp === 'undefined' || !wp.media) {
            alert('WordPress media uploader not available.');
            return;
        }
        mediaUploader = wp.media({
            title: 'Select Ad Image',
            button: { text: 'Use this image' },
            multiple: false
        });
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            const urlInput = document.getElementById('ad-image-url');
            const preview = document.getElementById('ad-image-preview');
            if (urlInput) urlInput.value = attachment.url;
            if (preview) {
                preview.src = attachment.url;
                preview.style.display = 'block';
            }
        });
        mediaUploader.open();
    };

    window.ventureShowAdModal = function(id) {
        const modal = document.getElementById('venture-ad-modal');
        if (!modal) return;

        const titleEl = document.getElementById('ad-modal-title');
        const idEl = document.getElementById('ad-id');
        const titleInput = document.getElementById('ad-title');
        const targetInput = document.getElementById('ad-target-url');
        const imageUrlInput = document.getElementById('ad-image-url');
        const preview = document.getElementById('ad-image-preview');

        const isEdit = !!id && id != 0;

        if (titleEl) {
            titleEl.textContent = isEdit ? 'Edit Advertisement' : 'Add New Advertisement';
        }
        if (idEl) idEl.value = id || '';

        // Always start clean (edit pre-fill would require AJAX load of existing ad data)
        if (titleInput) titleInput.value = '';
        if (targetInput) targetInput.value = '';
        if (imageUrlInput) imageUrlInput.value = '';
        if (preview) {
            preview.src = '';
            preview.style.display = 'none';
        }

        modal.style.display = 'block';
    };

    window.ventureHideAdModal = function() {
        const modal = document.getElementById('venture-ad-modal');
        if (modal) modal.style.display = 'none';
    };

    window.ventureSaveAd = function() {
        const idEl = document.getElementById('ad-id');
        const titleEl = document.getElementById('ad-title');
        const clientEl = document.getElementById('ad-client-id');
        const imageEl = document.getElementById('ad-image-url');
        const targetEl = document.getElementById('ad-target-url');

        if (!idEl || !titleEl || !clientEl || !imageEl || !targetEl) {
            return alert('Form elements missing');
        }

        const id = idEl.value;
        const title = titleEl.value.trim();
        const clientId = clientEl.value;
        const imageUrl = imageEl.value;
        const targetUrl = targetEl.value.trim();

        if (!title || !imageUrl || !targetUrl) {
            return alert('All fields (title, image, target URL) are required');
        }

        const data = new FormData();
        data.append('action', 'venture_save_ad');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('id', id);
        data.append('client_id', clientId);
        data.append('title', title);
        data.append('image_url', imageUrl);
        data.append('target_url', targetUrl);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message || 'Error saving advertisement');
                }
            })
            .catch(() => alert('Network error while saving ad'));
    };

    window.ventureDeleteAd = function(id) {
        if (!confirm('Delete this ad and its analytics data?')) return;

        const data = new FormData();
        data.append('action', 'venture_delete_ad');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('id', id);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) location.reload();
                else alert('Error deleting ad');
            })
            .catch(() => alert('Network error'));
    };

    // ====================== CAMPAIGNS ======================
    window.ventureCreateCampaign = function() {
        const nameEl = document.getElementById('campaign-name');
        const modal = document.getElementById('venture-campaign-modal');
        if (nameEl) nameEl.value = '';
        if (modal) modal.style.display = 'block';
    };

    window.ventureHideCampaignModal = function() {
        const modal = document.getElementById('venture-campaign-modal');
        if (modal) modal.style.display = 'none';
    };

    window.ventureSaveCampaign = function() {
        const nameEl = document.getElementById('campaign-name');
        if (!nameEl) return;

        const name = nameEl.value.trim();
        if (!name) {
            alert('Please enter a campaign name');
            return;
        }

        const data = new FormData();
        data.append('action', 'venture_save_campaign');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('name', name);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    ventureHideCampaignModal();
                    location.reload();
                } else {
                    alert(res.message || 'Error creating campaign');
                }
            })
            .catch(() => alert('Network error'));
    };

    window.ventureDeleteCampaign = function(id) {
        if (!confirm('Delete this campaign?')) return;

        const data = new FormData();
        data.append('action', 'venture_delete_campaign');
        data.append('nonce', (window.ventureAdmin && ventureAdmin.nonce) || '');
        data.append('id', id);

        fetch((window.ventureAdmin && ventureAdmin.ajaxurl) || ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) location.reload();
                else alert('Error deleting campaign');
            })
            .catch(() => alert('Network error'));
    };

})();

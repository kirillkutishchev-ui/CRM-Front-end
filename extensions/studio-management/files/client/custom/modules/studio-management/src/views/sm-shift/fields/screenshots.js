define(['views/fields/attachment-multiple'], function (AttachmentMultiple) {
    return class extends AttachmentMultiple {
        afterRender() {
            super.afterRender();
            this.previewObserver?.disconnect();
            this.decoratePreviews();
            this.previewObserver = new MutationObserver(() => this.decoratePreviews());
            this.previewObserver.observe(this.element, {childList: true, subtree: true});
        }

        onRemove() {
            this.previewObserver?.disconnect();
            super.onRemove?.();
        }

        decoratePreviews() {
            const ids = this.model.get(this.name + 'Ids') || [];
            const names = this.model.get(this.name + 'Names') || {};
            const types = this.model.get(this.name + 'Types') || {};
            const optimized = this.model.get('screenshotOptimizations') || {};
            this.element.querySelectorAll('img[src]').forEach(image => {
                const url = new URL(image.getAttribute('src'), window.location.href);
                const id = url.searchParams.get('id');
                if (ids.includes(id) && optimized[id]) {
                    image.dataset.originalAttachmentId = id;
                    url.searchParams.set('id', optimized[id]);
                    image.src = url.toString();
                }
            });
            // In edit mode the native upload field renders plain images inside spans.
            this.element.querySelectorAll('img').forEach(image => {
                if (image.closest('a')) return;
                const id = image.dataset.originalAttachmentId || new URL(image.getAttribute('src'), window.location.href).searchParams.get('id');
                if (!ids.includes(id)) return;
                const link = document.createElement('a');
                link.href = this.getBasePath() + '?entryPoint=image&id=' + encodeURIComponent(id);
                link.dataset.id = id;
                image.replaceWith(link);
                link.appendChild(image);
            });
            // Older attachments can render as filename/download links rather than thumbnails.
            this.element.querySelectorAll('a[href]').forEach(link => {
                const url = new URL(link.getAttribute('href'), window.location.href);
                const id = link.dataset.id || url.searchParams.get('id');
                if (!ids.includes(id)) return;
                const isImage = String(types[id] || '').startsWith('image/') ||
                    /\.(png|jpe?g|gif|webp|bmp)$/i.test(names[id] || '');
                if (!isImage) return;
                link.dataset.action = 'showImagePreview';
                link.dataset.id = id;
                link.classList.add('sm-screenshot-preview');
                link.setAttribute('title', this.translate('Preview Screenshot', 'labels', 'SmShift'));
            });
        }
    };
});

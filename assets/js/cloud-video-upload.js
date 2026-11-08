/* The normal PlayTube uploader stages the file; this step stores and processes
 * it before the existing metadata/publish form is enabled. No callback URL. */
(function () {
    'use strict';

    window.PT_CloudVideoUpload = function (data, complete) {
        var panel = document.getElementById('cloud-video-status');
        if (!panel || data.status !== 200) {
            complete(data);
            return;
        }
        var message = panel.querySelector('[data-cloud-message]');
        var retry = panel.querySelector('button');
        var endpoint = panel.getAttribute('data-endpoint');
        var pipelineId = 0;
        var deadline = Date.now() + 15 * 60 * 1000;
        var running = false;

        function request(url, options) {
            return fetch(url, options).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.ok) {
                        throw new Error(body.error || 'Video processing could not be checked');
                    }
                    return body;
                });
            });
        }

        function failed(error) {
            running = false;
            panel.classList.remove('alert-info');
            panel.classList.add('alert-danger');
            message.textContent = error.message;
            // Once a row exists, retry only reads status: it never re-uploads.
            retry.hidden = !pipelineId;
            document.getElementById('submit-btn').disabled = true;
        }

        function check(body) {
            if (!document.body.contains(panel)) { return; }
            var video = body.video;
            pipelineId = video.id;
            var status = video.processing.status;
            if (status === 'errored' || status === 'error') {
                throw new Error(video.error || 'Mux could not process this video');
            }
            if (status === 'ready' && video.processing.playback_id) {
                message.textContent = 'Stored on Vidara. Mux playback is ready — add details to publish.';
                complete(data);
                return;
            }
            if (Date.now() > deadline) {
                throw new Error('Processing is taking longer than expected. Check again without uploading twice.');
            }
            message.textContent = 'Stored on Vidara. Mux is processing your video…';
            setTimeout(poll, 5000);
        }

        function poll() {
            if (!document.body.contains(panel)) { return; }
            request(endpoint + '?action=status&id=' + encodeURIComponent(pipelineId), {
                credentials: 'same-origin', cache: 'no-store'
            }).then(check).catch(failed);
        }

        retry.onclick = function () {
            if (running || !pipelineId) { return; }
            running = true;
            retry.hidden = true;
            panel.classList.remove('alert-danger');
            panel.classList.add('alert-info');
            deadline = Date.now() + 15 * 60 * 1000;
            poll();
        };
        running = true;
        message.textContent = 'Uploading your video to Vidara and Mux…';
        retry.hidden = true;
        document.getElementById('submit-btn').disabled = true;
        var form = new FormData();
        form.append('action', 'process');
        form.append('uploaded_id', data.uploaded_id);
        form.append('title', data.file_name);
        var session = document.querySelector('.main_session');
        form.append('hash', session ? session.value : '');
        request(endpoint, { method: 'POST', credentials: 'same-origin', body: form })
            .then(check).catch(failed);
    };
}());

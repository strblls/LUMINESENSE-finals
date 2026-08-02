        let ocrDone = true;
        let ocrRunning = false;

        function preprocessImage(file) {
            return new Promise(function (resolve) {
                var img = new Image();
                img.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = img.width;
                    canvas.height = img.height;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);

                    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    var data = imageData.data;
                    for (var i = 0; i < data.length; i += 4) {
                        var gray = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
                        var val = gray > 160 ? 255 : 0;
                        data[i] = data[i + 1] = data[i + 2] = val;
                    }
                    ctx.putImageData(imageData, 0, 0);
                    resolve(canvas);
                };
                img.src = URL.createObjectURL(file);
            });
        }

        document.getElementById('id_image').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            ocrDone = false;
            ocrRunning = true;
            const statusEl = document.getElementById('ocr_status');
            const progressWrap = document.getElementById('ocr_progress_wrap');
            const progressBar = document.getElementById('ocr_progress');
            statusEl.textContent = 'Preprocessing image...';
            statusEl.className = 'text-info small mt-1';
            progressWrap.style.display = 'block';
            progressWrap.style.backgroundColor = '#f9edfa';
            progressBar.style.width = '0%';

            preprocessImage(file).then(function (canvas) {
                statusEl.textContent = 'Scanning ID...';
                return Tesseract.recognize(canvas, 'eng', {
                    logger: function (m) {
                        if (m.status === 'recognizing text') {
                            var pct = Math.round(m.progress * 100);
                            progressBar.style.width = pct + '%';
                            progressBar.setAttribute('aria-valuenow', pct);
                        }
                    }
                });
            }).then(function ({ data: { text } }) {
                const trimmed = text.trim();
                ocrDone = true;
                ocrRunning = false;
                document.getElementById('ocr_text').value = trimmed;
                if (trimmed) {
                    progressBar.style.width = '100%';
                    progressBar.setAttribute('aria-valuenow', 100);
                    statusEl.textContent = 'ID scanned successfully.';
                    statusEl.className = 'text-success small mt-1';
                } else {
                    progressBar.style.width = '100%';
                    progressBar.setAttribute('aria-valuenow', 100);
                    statusEl.textContent = 'No text could be read. Manual review will be required.';
                    statusEl.className = 'text-warning small mt-1';
                }
            }).catch(function (err) {
                ocrDone = true;
                ocrRunning = false;
                progressBar.style.width = '100%';
                progressBar.setAttribute('aria-valuenow', 100);
                statusEl.textContent = 'Failed to scan ID. Manual review will be required.';
                statusEl.className = 'text-danger small mt-1';
                console.error('Tesseract error:', err);
            });
        });

        function showSignupModal() {
            if (ocrRunning) {
                alert('Please wait \u2014 ID scan is still in progress.');
                return;
            }

            const pass    = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;

            if (pass !== confirm) {
                alert('Passwords do not match! Please check again.');
                return;
            }
            if (pass.length < 8) {
                alert('Password must be at least 8 characters long.');
                return;
            }

            document.getElementById('notify-modal').style.display = 'flex';
        }

        function hideSignupModal() {
            document.getElementById('notify-modal').style.display = 'none';
        }

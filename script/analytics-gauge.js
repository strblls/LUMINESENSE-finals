function drawGauge(canvasId, value, max = 100, color = '#30004f') {
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2;
    const cy = canvas.height - 10;
    const radius = 90;
    const startAngle = Math.PI;
    const endAngle = 2 * Math.PI;
    const progress = startAngle + (value / max) * Math.PI;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    //Background arc
    ctx.beginPath();
    ctx.arc(cx, cy, radius, startAngle, endAngle);
    ctx.strokeStyle = '#cccccc';
    ctx.lineWidth = 16;
    ctx.lineCap = 'round';
    ctx.stroke();

    //Progress arc
    ctx.beginPath();
    ctx.arc(cx, cy, radius, startAngle, progress);
    ctx.strokeStyle = color;
    ctx.lineWidth = 16;
    ctx.lineCap = 'round';
    ctx.stroke();
}

//sample static draws
drawGauge('energyGauge', 36, 100, '#f2ba00');
drawGauge('luxGauge', 58, 100, '#790faf');
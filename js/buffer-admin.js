document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'buffer_mode') {
        const dateWrapper = document.getElementById('buffer_date_wrapper');
        if (dateWrapper) {
            if (e.target.value === 'customScheduled') {
                dateWrapper.style.display = 'block';
            } else {
                dateWrapper.style.display = 'none';
            }
        }
    }
});
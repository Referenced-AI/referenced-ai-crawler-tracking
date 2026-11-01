document.addEventListener('click', function (e) {
	var open = e.target.closest('[data-ref-dialog]');
	if (open) { document.getElementById(open.dataset.refDialog).showModal(); }
	var close = e.target.closest('[data-ref-close]');
	if (close) { close.closest('dialog').close(); }
});

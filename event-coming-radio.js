window.addEventListener('load', function () {
	const fields = document.querySelectorAll('[class*="gfield--type-section"]');
	const field = fields[1];

	if (!field) return;

	const radioWrapper = document.createElement('div');
	radioWrapper.id = 'coming_radio_wrapper';
	radioWrapper.style.marginTop = '10px';

	const label = document.createElement('label');
	label.innerText = 'Are you coming to the event?';
	label.style.display = 'block';
	label.style.marginBottom = '5px';
	label.style.color = "#000";
	label.style.fontSize = "20px";
	label.style.width = "400px";

	const radioContainer = document.createElement('div');
	radioContainer.style.display = 'flex';
	radioContainer.style.flexDirection = 'row';
	radioContainer.style.gap = '6px';

	// Radio "Yes"
	const yesRadio = document.createElement('input');
	yesRadio.type = 'radio';
	yesRadio.name = 'coming';
	yesRadio.value = 'yes';
	yesRadio.id = 'coming_yes';

	const yesLabel = document.createElement('label');
	yesLabel.htmlFor = 'coming_yes';
	yesLabel.innerText = 'Yes';
	yesLabel.prepend(yesRadio);

	// Radio "No"
	const noRadio = document.createElement('input');
	noRadio.type = 'radio';
	noRadio.name = 'coming';
	noRadio.value = 'no';
	noRadio.id = 'coming_no';

	const noLabel = document.createElement('label');
	noLabel.htmlFor = 'coming_no';
	noLabel.innerText = 'No';
	noLabel.prepend(noRadio); 

	radioContainer.appendChild(yesLabel);
	radioContainer.appendChild(noLabel);
	radioWrapper.appendChild(label);
	radioWrapper.appendChild(radioContainer);

	field.insertAdjacentElement('beforebegin', radioWrapper);
});

document.addEventListener('change', function (event) {
	if (event.target && event.target.name === 'coming' && event.target.value === 'yes') {
		const targetButton = document.querySelector('.gpnf-add-entry');
		if (targetButton) {
			targetButton.click();
		}
	}
}); 
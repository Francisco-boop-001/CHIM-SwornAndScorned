'use strict';

const picker = document.getElementById('cast-picker');
if (picker) {
    const boxes = [...picker.querySelectorAll('input[name="cast[]"]')];
    const count = document.getElementById('cast-count');
    const update = () => { count.textContent = `${boxes.filter(box => box.checked).length} of ${boxes.length} selected`; };
    for (const [id, checked] of [['cast-all', true], ['cast-clear', false]]) {
        const button = document.getElementById(id);
        button.hidden = false;
        button.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = checked; });
            update();
        });
    }
    picker.addEventListener('change', update);
    picker.closest('form').addEventListener('submit', () => {
        let field = picker.querySelector('input[name="cast_json"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'cast_json';
            picker.append(field);
        }
        field.value = JSON.stringify(boxes.filter(box => box.checked).map(box => box.value));
        boxes.forEach(box => box.removeAttribute('name'));
    });
    window.addEventListener('pageshow', update);
    update();
}

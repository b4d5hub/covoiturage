// Ajout et retrait des étapes du formulaire de publication, à partir du prototype Symfony.
document.querySelectorAll('[data-collection]').forEach((collection) => {
    const list = collection.querySelector('[data-collection-list]');
    let index = list.querySelectorAll('[data-collection-item]').length;

    collection.querySelector('[data-collection-add]').addEventListener('click', () => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = collection.dataset.prototype.replace(/__name__/g, String(index++));
        list.appendChild(wrapper.firstElementChild);
    });

    collection.addEventListener('click', (event) => {
        if (event.target.matches('[data-collection-remove]')) {
            event.target.closest('[data-collection-item]').remove();
        }
    });
});

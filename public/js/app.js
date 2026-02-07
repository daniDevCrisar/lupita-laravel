function copiarTexto(texto) {

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto);
        return;
    }

    // Fallback
    const textarea = document.createElement("textarea");
    textarea.value = texto;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand("copy");
    document.body.removeChild(textarea);
}

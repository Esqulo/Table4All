type CompressOptions = {
    maxWidth?: number;
    maxHeight?: number;
    quality?: number;
    type?: 'image/jpeg' | 'image/webp';
};

export async function compressImage(
    file: File,
    { maxWidth = 1920, maxHeight = 1920, quality = 0.85, type = 'image/jpeg' }: CompressOptions = {},
): Promise<File> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);

            let { width, height } = img;
            const scale = Math.min(maxWidth / width, maxHeight / height, 1);
            width = Math.round(width * scale);
            height = Math.round(height * scale);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            canvas.getContext('2d')!.drawImage(img, 0, 0, width, height);

            canvas.toBlob(
                (blob) => {
                    if (!blob) { reject(new Error('Canvas toBlob failed')); return; }
                    const ext = type === 'image/webp' ? '.webp' : '.jpg';
                    const name = file.name.replace(/\.[^.]+$/, ext);
                    resolve(new File([blob], name, { type }));
                },
                type,
                quality,
            );
        };

        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Image failed to load')); };
        img.src = url;
    });
}

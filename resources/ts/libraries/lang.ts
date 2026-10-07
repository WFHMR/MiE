declare const nsLanguage;

/**
 * Namespace ที่เก็บ string แปลของ core
 *
 * ค่านี้ต้องตรงกับ key ที่ resources/views/layout/_header-script.blade.php
 * ลงทะเบียนไว้ใน Hook::filter( 'ns.langFiles', [ ... ] ) เสมอ
 * ถ้าไม่ตรง __() จะหา namespace ที่ไม่มีข้อมูลและ fallback เป็น English key เงียบๆ
 *
 * อย่าเปลี่ยนเป็นชื่อแบรนด์ของร้าน ค่านี้เป็น key ภายในของตัวโปรแกรม
 * ตั้งใจใช้ตัวพิมพ์เล็กเพื่อไม่ให้ชนกับ namespace ของ module
 * ซึ่งมาจาก ucwords( Str::camel( ชื่อ module ) ) จึงขึ้นต้นด้วยตัวใหญ่เสมอ
 *
 * เดิมใช้ชื่อโปรเจกต์ต้นน้ำ แต่ค่านี้ถูกพิมพ์ลง HTML ของทุกหน้า ไม่ได้ซ่อนอยู่ข้างใน
 */
export const CORE_LANG_NAMESPACE = 'core';

export const __   =   function( text, namespace = CORE_LANG_NAMESPACE ) {
    return nsLanguage.getEntries( namespace ) ? (nsLanguage.getEntries( namespace )[ text ] || text ) : text;
}

export const __m   =   function( text, namespace ) {
    return nsLanguage.getEntries( namespace ) ? (nsLanguage.getEntries( namespace )[ text ] || text ) : text;
}

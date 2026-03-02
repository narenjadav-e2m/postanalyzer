import { useEffect } from "react";
import feather from "feather-icons";

export default function MetaRow({ label, value, isHtml = false }) {

    useEffect(() => {
        feather.replace();
    }, []);

    if (value === undefined || value === null || (Array.isArray(value) && value.length === 0))
        return null;

    const content = Array.isArray(value) ? value.join(', ') : String(value);

    return (
        <div className="rr-wrap">
            <strong className="rr-label">{label}:</strong>
            {isHtml ? (<span dangerouslySetInnerHTML={{ __html: content }} />) : (<span>{content}</span>)}
            <i data-feather="info" className="inline ml-2 w-4 cursor-pointer"></i>
        </div>
    );
}
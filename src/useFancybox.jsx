import { useEffect } from "react";
import { Fancybox } from "@fancyapps/ui/dist/fancybox/";
import "@fancyapps/ui/dist/fancybox/fancybox.css";

export default function useFancybox(rootRef, options = {}) {
    useEffect(() => {
        if (rootRef?.current) {
            // Bind Fancybox once for both groups
            Fancybox.bind(rootRef.current.querySelectorAll('[data-fancybox="featured"]'), options);
            Fancybox.bind(rootRef.current.querySelectorAll('[data-fancybox="attached"]'), options);

            return () => Fancybox.unbind(rootRef.current);
        }
    }, [rootRef, options]);
}

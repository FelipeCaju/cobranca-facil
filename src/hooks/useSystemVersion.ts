import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type SystemVersion = {
  version: string;
  build: string;
  commit: string | null;
  label: string;
};

export const useSystemVersion = () => {
  const [label, setLabel] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    apiFetch<SystemVersion>("/api/version")
      .then((result) => {
        if (active) setLabel(result.label);
      })
      .catch(() => {
        if (active) setLabel(null);
      });

    return () => {
      active = false;
    };
  }, []);

  return label;
};
